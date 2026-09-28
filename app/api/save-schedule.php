<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/python.php';
/**
 * BCP Phase 4C — DEMO-ONLY first-save endpoint.
 * POST {"save_token":"...","confirm":true} on the same browser session.
 * Do not deploy as an official/authenticated Save API.
 * Existing ACTIVE batches are never overwritten or superseded here.
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

final class SaveRejected extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $saveStatus,
        string $message
    ) {
        parent::__construct($message);
    }
}
function rejectSave(int $http, string $status, string $message): never
{
    throw new SaveRejected($http, $status, $message);
}
function sendSaveResponse(int $http, array $data): never
{
    http_response_code($http);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function localApi(string $url, ?array $body = null): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        rejectSave(502, 'API_UNAVAILABLE', 'Could not initialize internal API request.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if ($body !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/json'];
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($raw === false || $status !== 200) {
        error_log('BCP save internal API: ' . $url . ' ' . $status . ' ' . $error . ' ' . substr((string)$raw, 0, 500));
        rejectSave(502, 'API_UNAVAILABLE', 'Final scheduling input/audit could not be verified. No schedule saved.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        rejectSave(502, 'BAD_API_RESPONSE', 'Invalid response from the internal API.');
    }
    return $decoded;
}
function minuteOfDay(string $time): int
{
    if (!preg_match('/^(\d{2}):(\d{2})(?::00)?$/', $time, $part)) {
        rejectSave(422, 'INVALID_TIME', 'Invalid timetable clock time.');
    }
    $hours = (int)$part[1];
    $minutes = (int)$part[2];
    if ($hours > 23 || $minutes > 59) {
        rejectSave(422, 'INVALID_TIME', 'Invalid timetable clock time.');
    }
    return $hours * 60 + $minutes;
}
function periodLockName(int $id): string
{
    return 'BCP_SCHED_SAVE_PERIOD_' . $id;
}

$pdo = null;
$locked = false;
$committed = false;
$lockName = '';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        rejectSave(405, 'POST_ONLY', 'Send a POST request to save a confirmed preview.');
    }
    // Development safety: local XAMPP and DEMO data only.
    if (!function_exists('curl_init')) {
        rejectSave(500, 'CURL_UNAVAILABLE', 'PHP cURL is required.');
    }
    $request = json_decode(file_get_contents('php://input'), true);
    if (!is_array($request) || ($request['confirm'] ?? null) !== true || !is_string($request['save_token'] ?? null)) {
        rejectSave(400, 'CONFIRMATION_REQUIRED', 'Send save_token and confirm=true.');
    }
    ini_set('session.use_strict_mode', '1');
    session_name('BCP_SCHED_DEMO');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'path' => '/',
    ]);
    if (!session_start()) {
        rejectSave(500, 'SESSION_UNAVAILABLE', 'Cannot read saved preview session.');
    }
    $preview = $_SESSION['bcp_schedule_preview'] ?? null;
    session_write_close();
    if (!is_array($preview) || !hash_equals((string)($preview['token'] ?? ''), $request['save_token'])) {
        rejectSave(403, 'INVALID_PREVIEW_TOKEN', 'Preview token is missing or does not match this browser session.');
    }
    $previewAgeSeconds = time() - (int)($preview['created_at'] ?? 0);

    if ($previewAgeSeconds > 1200) {
        rejectSave(
            409,
            'PREVIEW_EXPIRED',
            'Preview has expired. Generate a new schedule.',
            [
                'server_now' => time(),
                'preview_created_at' => (int)($preview['created_at'] ?? 0),
                'preview_age_seconds' => $previewAgeSeconds,
            ]
        );
    }
    $program = $preview['program'];
    $period = $preview['academic_period'];
    $result = $preview['result'];
    if (($program['program_code'] ?? null) !== 'BSIT' || ($period['period_status'] ?? null) !== 'DEMO') {
        rejectSave(403, 'BSIT_DEMO_ONLY', 'Only BSIT DEMO previews are supported in this phase.');
    }
    if (($result['success'] ?? false) !== true || ($result['audit']['passed'] ?? false) !== true
        || ($result['status'] ?? '') !== 'DEMO_PREVIEW_GENERATED'
        || ($result['existing_snapshot_constraints_applied'] ?? false) !== true
        || !is_array($result['assignments'] ?? null)
    ) {
        rejectSave(422, 'UNAPPROVED_PREVIEW', 'The server-side preview was not independently approved.');
    }
    $periodId = (int)$period['academic_period_id'];
    $programId = (int)$program['program_id'];
    $pdo = getDatabase();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // All schedule writers MUST use this same per-period lock convention.
    $lockName = periodLockName($periodId);
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:name, 10)');
    $lockStmt->execute(['name' => $lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        rejectSave(409, 'SAVE_BUSY', 'Another timetable save is running. Try again.');
    }
    $locked = true;
    $pdo->beginTransaction();

    // Re-read the CURRENT database facts and saved schedules while holding the lock.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $url = $scheme . '://' . $host
        . dirname($_SERVER['SCRIPT_NAME'])
        . '/scheduling-input.php?'
        . http_build_query([
            'program' => $program['program_code'],
            'academic_year' => $period['academic_year'],
            'semester' => $period['semester'],
        ]);
    $current = localApi($url);
    if (($current['success'] ?? false) !== true || ($current['status'] ?? '') !== 'BASIC_INPUT_READY'
        || ($current['data_origin'] ?? null) !== 'DEMO'
        || (int)($current['program']['program_id'] ?? -1) !== $programId
        || (int)($current['academic_period']['academic_period_id'] ?? -1) !== $periodId
    ) {
        rejectSave(409, 'INPUTS_NOT_READY', 'Current database inputs are not ready for this preview.');
    }
    $freshInput = $current['scheduling_input'];
    $freshHash = hash('sha256', json_encode(
        $freshInput,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    ));
    if (!hash_equals((string)$preview['input_hash'], $freshHash)) {
        rejectSave(409, 'STALE_PREVIEW', 'Scheduling inputs or existing saved timetables changed. Generate a new preview.');
    }

    // The independent checker is run again on fresh DB facts, not on browser-submitted assignments.
    $audit = localApi(pythonBaseUrl() . '/api/schedules/audit', [
        'input' => $current,
        'result' => $result,
    ]);
    if (($audit['passed'] ?? false) !== true || ($audit['status'] ?? '') !== 'AUDIT_PASSED'
        || (int)($audit['required_meetings'] ?? -1) !== (int)$result['required_meetings']
        || (int)($audit['returned_meetings'] ?? -1) !== count($result['assignments'])
    ) {
        error_log('BCP save rejected by independent audit: ' . json_encode($audit['errors'] ?? []));
        rejectSave(422, 'FINAL_AUDIT_FAILED', 'Final independent conflict audit failed. No schedule saved.');
    }

    // Lock ACTIVE batch records in our transaction; never overwrite any program.
    $activeStmt = $pdo->prepare(
        "SELECT batch_id, program_id FROM schedule_batches
         WHERE academic_period_id = :period_id AND status = 'ACTIVE' FOR UPDATE"
    );
    $activeStmt->execute(['period_id' => $periodId]);
    $active = $activeStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($active as $batch) {
        if ((int)$batch['program_id'] === $programId) {
            rejectSave(409, 'PROGRAM_ALREADY_SAVED', 'BSIT already has an ACTIVE timetable. Replacement is a separate workflow.');
        }
    }
    // Fresh input snapshot is exact; this checks the DB state seen by this transaction too.
    $savedStmt = $pdo->prepare(
        "SELECT m.meeting_id, m.teacher_id, m.room_id, m.day_of_week,
                m.start_time, m.end_time, ss.section_id, ss.subject_id
         FROM schedule_meetings AS m
         INNER JOIN schedule_batches AS b ON b.batch_id = m.batch_id
         INNER JOIN section_subjects AS ss ON ss.section_subject_id = m.section_subject_id
         WHERE b.academic_period_id = :period_id AND b.status = 'ACTIVE'
         FOR UPDATE"
    );
    $savedStmt->execute(['period_id' => $periodId]);
    $saved = $savedStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($saved) !== count($freshInput['existing_meetings'])) {
        rejectSave(409, 'STALE_PREVIEW', 'Active saved meeting count changed. Regenerate the preview.');
    }

    // Resolve section_subject_id from FRESH database facts (preview only has section and subject IDs).
    $subjectMap = [];
    foreach ($freshInput['section_subjects'] as $subject) {
        $key = (int)$subject['section_id'] . ':' . (int)$subject['subject_id'];
        if (isset($subjectMap[$key])) {
            rejectSave(422, 'DUPLICATE_SUBJECT_INPUT', 'Duplicate database section-subject mapping.');
        }
        $subjectMap[$key] = (int)$subject['section_subject_id'];
    }
    $prepared = [];
    $uniqueAssignments = [];
    foreach ($result['assignments'] as $meeting) {
        $sectionId = (int)$meeting['section_id'];
        $subjectId = (int)$meeting['subject_id'];
        $key = $sectionId . ':' . $subjectId;
        if (!isset($subjectMap[$key])) {
            rejectSave(422, 'SUBJECT_MISMATCH', 'Preview contains a section-subject not in the current database.');
        }
        $teacherId = (int)$meeting['teacher_id'];
        $roomId = $meeting['room_id'] === null ? null : (int)$meeting['room_id'];
        $mode = $meeting['delivery_mode'];
        $day = $meeting['day_of_week'];
        $start = minuteOfDay((string)$meeting['start_time']);
        $end = minuteOfDay((string)$meeting['end_time']);
        $uniqueKey = $subjectMap[$key] . ':' . $mode;
        if (isset($uniqueAssignments[$uniqueKey])) {
            rejectSave(422, 'DUPLICATE_MEETING', 'Preview has a duplicate class meeting.');
        }
        $uniqueAssignments[$uniqueKey] = true;
        foreach ($saved as $old) {
            if ($day !== $old['day_of_week']) {
                continue;
            }
            $oldStart = minuteOfDay((string)$old['start_time']);
            $oldEnd = minuteOfDay((string)$old['end_time']);
            if ($start >= $oldEnd || $oldStart >= $end) {
                continue;
            }
            if (
                $teacherId === (int)$old['teacher_id']
                || $sectionId === (int)$old['section_id']
                || $subjectId === (int)$old['subject_id']
                || ($roomId !== null && $old['room_id'] !== null && $roomId === (int)$old['room_id'])
            ) {
                rejectSave(
                    409,
                    'SAVED_SCHEDULE_CONFLICT',
                    'Meeting overlaps a saved teacher, section, subject, or room assignment. Regenerate.'
                );
            }
        }
        $prepared[] = [
            'section_subject_id' => $subjectMap[$key],
            'teacher_id' => $teacherId,
            'room_id' => $roomId,
            'mode' => $mode,
            'day' => $day,
            'start_time' => sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60),
            'end_time' => sprintf('%02d:%02d:00', intdiv($end, 60), $end % 60),
        ];
    }
    if (count($prepared) !== (int)$result['required_meetings']) {
        rejectSave(422, 'INCOMPLETE_PREVIEW', 'Number of assignments does not match current requirements.');
    }

    $batchStmt = $pdo->prepare(
        "INSERT INTO schedule_batches (academic_period_id, program_id, data_origin, status)
         VALUES (:period_id, :program_id, 'DEMO', 'ACTIVE')"
    );
    $batchStmt->execute(['period_id' => $periodId, 'program_id' => $programId]);
    $batchId = (int)$pdo->lastInsertId();
    $insertStmt = $pdo->prepare(
        "INSERT INTO schedule_meetings
         (batch_id, section_subject_id, teacher_id, room_id, delivery_mode, day_of_week, start_time, end_time)
         VALUES (:batch_id, :section_subject_id, :teacher_id, :room_id, :mode, :day, :start_time, :end_time)"
    );
    foreach ($prepared as $meeting) {
        $insertStmt->execute(['batch_id' => $batchId] + $meeting);
    }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM schedule_meetings WHERE batch_id = :batch_id');
    $countStmt->execute(['batch_id' => $batchId]);
    if ((int)$countStmt->fetchColumn() !== count($prepared)) {
        rejectSave(500, 'INCOMPLETE_INSERT', 'Database insert count mismatch; rolling back.');
    }
    $pdo->commit();
    $committed = true;

    try {
        if (session_start()) {
            if (hash_equals((string)($_SESSION['bcp_schedule_preview']['token'] ?? ''), $request['save_token'])) {
                unset($_SESSION['bcp_schedule_preview']);
            }
            session_write_close();
        }
    } catch (Throwable $sessionError) {
        error_log('BCP saved but could not clear preview token: ' . $sessionError->getMessage());
    }
    $responseStatus = 200;
    $responsePayload = [
        'success' => true,
        'status' => 'DEMO_SCHEDULE_SAVED',
        'batch_id' => $batchId,
        'program' => 'BSIT',
        'academic_period_id' => $periodId,
        'saved_meetings' => count($prepared),
        'database_write' => true,
        'school_wide_validation_complete' => false,
        'message' => 'Demo timetable saved. Official/policy validation remains pending.',
    ];
} catch (SaveRejected $error) {
    $responseStatus = $error->httpStatus;
    $responsePayload = [
        'success' => false,
        'status' => $error->saveStatus,
        'message' => $error->getMessage(),
        'database_write' => $committed,
    ];
} catch (Throwable $error) {
    error_log('BCP Phase 4C save failed: ' . $error->getMessage());
    $responseStatus = 500;
    $responsePayload = [
        'success' => false,
        'status' => 'SAVE_FAILED',
        'message' => $committed
            ? 'Timetable was saved, but post-save processing failed. Check the saved batch before retrying.'
            : 'Save failed. No timetable was committed. Check server logs.',
        'database_write' => $committed,
    ];
}
// Release resources BEFORE replying; all error paths must roll back.
if ($pdo instanceof PDO) {
    if ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $cleanupError) {
            error_log($cleanupError->getMessage());
        }
    }
    if ($locked) {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
        } catch (Throwable $cleanupError) {
            error_log($cleanupError->getMessage());
        }
    }
}
sendSaveResponse($responseStatus, $responsePayload);
