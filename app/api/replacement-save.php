<?php
declare(strict_types=1);
/** Phase 4G local DEMO-only atomic replacement of exactly one BSIT ACTIVE batch. */
require_once __DIR__ . '/replacement-common.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(100);
$pdo = null;
$hasLock = false;
$committed = false;
$lockName = '';
$http = 500;
$out = ['success' => false, 'status' => 'REPLACEMENT_FAILED', 'database_write' => false];
try {
    rgGuard('POST');
    $req = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($req) || ($req['confirm'] ?? null) !== true || !is_string($req['replace_token'] ?? null)) {
        rgReject(400, 'CONFIRMATION_REQUIRED', 'Submit the replacement preview token with confirm=true.');
    }
    rgSession();
    $preview = $_SESSION['bcp_replacement_preview'] ?? null;
    session_write_close();
    if (!is_array($preview) || !is_string($preview['token'] ?? null)
        || !hash_equals($preview['token'], $req['replace_token'])) {
        rgReject(403, 'INVALID_PREVIEW_TOKEN', 'No matching server-side replacement preview for this session.');
    }
    if (time() - (int)$preview['created_at'] > 1200) {
        rgReject(409, 'PREVIEW_EXPIRED', 'Preview expired. Generate a new replacement preview.');
    }
    $oldBatch = (int)$preview['batch_id'];
    $periodId = (int)$preview['period_id'];
    $programId = (int)$preview['program_id'];
    $year = (string)$preview['year'];
    $semester = (int)$preview['semester'];
    $result = $preview['result'];
    if ($oldBatch < 1 || $periodId < 1 || $programId < 1 || !is_array($result)
        || ($result['success'] ?? null) !== true
        || ($result['status'] ?? '') !== 'DEMO_PREVIEW_GENERATED'
        || ($result['audit']['passed'] ?? false) !== true
        || ($result['existing_snapshot_constraints_applied'] ?? false) !== true
        || !is_array($result['assignments'] ?? null)) {
        rgReject(422, 'UNAPPROVED_PREVIEW', 'Server-side replacement preview was not independently approved.');
    }
    $pdo = getDatabase();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $lockName = rgLockName($periodId); // Same lock convention as Phase 4C first save.
    $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $stmt->execute(['lock_name' => $lockName]);
    if ((int)$stmt->fetchColumn() !== 1) {
        rgReject(409, 'SAVE_BUSY', 'Another timetable save is running. Retry after it finishes.');
    }
    $hasLock = true;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT batch_id, program_id, data_origin FROM schedule_batches
        WHERE academic_period_id = :period_id AND status = 'ACTIVE' ORDER BY batch_id FOR UPDATE");
    $stmt->execute(['period_id' => $periodId]);
    $active = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $own = array_values(array_filter($active, fn($b) => (int)$b['program_id'] === $programId));
    if (count($own) !== 1 || (int)$own[0]['batch_id'] !== $oldBatch || $own[0]['data_origin'] !== 'DEMO') {
        rgReject(409, 'BASELINE_CHANGED', 'Expected ACTIVE BSIT batch is no longer the only ACTIVE BSIT timetable.');
    }
    // Lock current meetings, including previously saved schedules of other programs.
    $stmt = $pdo->prepare('SELECT m.meeting_id FROM schedule_meetings m
        JOIN schedule_batches b ON b.batch_id = m.batch_id
        WHERE b.academic_period_id = :period_id AND b.status = \'ACTIVE\'
        ORDER BY m.meeting_id FOR UPDATE');
    $stmt->execute(['period_id' => $periodId]);
    $lockedIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    // IMPORTANT: do not assume the saved schedule snapshot seen by the solver is still current.
    [$inputHttp, $fresh] = rgCall(rgInputUrl($oldBatch, $year, $semester));
    if ($inputHttp !== 200) {
        rgReject(409, 'CURRENT_INPUTS_UNAVAILABLE', 'Cannot verify current replacement inputs. Old timetable remains ACTIVE.');
    }
    rgVerifyInput($fresh, $oldBatch, $periodId, $programId);
    if (!hash_equals((string)$preview['input_hash'], rgHash($fresh['scheduling_input']))
        || !hash_equals((string)$preview['baseline_sha256'], $fresh['replacement_baseline']['baseline_sha256'])
        || (int)$preview['baseline_count'] !== (int)$fresh['replacement_baseline']['saved_meetings']) {
        rgReject(409, 'STALE_PREVIEW', 'Sections, teachers, time slots or saved timetables changed. Regenerate the replacement preview.');
    }
    // Independently compare the current LOCKED DB rows against the fresh external snapshot.
    // Same columns/order as Phase 4B scheduling-input.php for stable SHA-256 comparison.
    $stmt = $pdo->prepare(<<<'SQL'
SELECT m.meeting_id, m.batch_id, b.academic_period_id,
       b.program_id, b.data_origin,
       ss.section_subject_id, sec.section_id,
       sec.program_id AS section_program_id,
       sec.academic_period_id AS section_academic_period_id,
       sec.student_count AS section_student_count,
       subj.subject_id, subj.program_id AS subject_program_id,
       m.teacher_id, t.program_id AS teacher_program_id,
       m.room_id, r.program_id AS room_program_id,
       r.capacity AS room_capacity,
       m.delivery_mode, m.day_of_week, m.start_time, m.end_time
FROM schedule_meetings m
JOIN schedule_batches b ON b.batch_id = m.batch_id
JOIN section_subjects ss ON ss.section_subject_id = m.section_subject_id
JOIN sections sec ON sec.section_id = ss.section_id
JOIN subjects subj ON subj.subject_id = ss.subject_id
JOIN teachers t ON t.teacher_id = m.teacher_id
LEFT JOIN rooms r ON r.room_id = m.room_id
WHERE b.academic_period_id = :period_id AND b.status = 'ACTIVE'
ORDER BY m.meeting_id
SQL);
    $stmt->execute(['period_id' => $periodId]);
    // Match the scheduling-input loader's PDO default fetch mode exactly;
    // its baseline_sha256 was produced from fetchRows()->fetchAll().
    $nowSaved = $stmt->fetchAll();
    if (count($nowSaved) !== count($lockedIds)
        || array_map(fn($row) => (int)$row['meeting_id'], $nowSaved) !== $lockedIds) {
        rgReject(409, 'ACTIVE_SCHEDULE_CHANGED', 'Saved meeting identities changed during final validation.');
    }
    $ownRows = [];
    $otherRows = [];
    foreach ($nowSaved as $row) {
        if ((int)$row['program_id'] === $programId) {
            if ((int)$row['batch_id'] !== $oldBatch) { rgReject(409, 'BASELINE_CHANGED', 'Unexpected selected-program saved meeting.'); }
            $ownRows[] = $row;
        } else {
            $otherRows[] = $row;
        }
    }
    if (count($ownRows) !== (int)$preview['baseline_count']
        || !hash_equals((string)$preview['baseline_sha256'], rgHash($ownRows))
        || !hash_equals(rgHash($fresh['scheduling_input']['existing_meetings']), rgHash($otherRows))) {
        rgReject(409, 'STALE_SAVED_SCHEDULE', 'Locked saved schedule records differ from the audited snapshot.');
    }
    // Re-run the independent Python audit AGAINST CURRENT DB INPUTS; never trust browser assignments.
    [$auditHttp, $audit] = rgCall(pythonBaseUrl() . '/api/schedules/audit', [
        'input' => $fresh, 'result' => $result,
    ]);
    if ($auditHttp !== 200 || ($audit['passed'] ?? false) !== true
        || ($audit['status'] ?? '') !== 'AUDIT_PASSED'
        || (int)($audit['required_meetings'] ?? -1) !== (int)($result['required_meetings'] ?? -2)
        || (int)($audit['returned_meetings'] ?? -1) !== count($result['assignments'])
        || count($result['assignments']) !== count($fresh['scheduling_input']['section_subjects']) * 2) {
        error_log('BCP replacement audit failed: ' . rgJson($audit['errors'] ?? []));
        rgReject(422, 'FINAL_AUDIT_FAILED', 'Independent final audit did not approve this replacement.');
    }
    $subjectMap = [];
    foreach ($fresh['scheduling_input']['section_subjects'] as $item) {
        $key = (int)$item['section_id'] . ':' . (int)$item['subject_id'];
        if (isset($subjectMap[$key])) { rgReject(422, 'DUPLICATE_SUBJECT_INPUT', 'Duplicate section-subject mapping.'); }
        $subjectMap[$key] = (int)$item['section_subject_id'];
    }
    $newMeetings = [];
    $unique = [];
    foreach ($result['assignments'] as $meeting) {
        $section = (int)$meeting['section_id']; $subject = (int)$meeting['subject_id'];
        $key = $section . ':' . $subject;
        if (!isset($subjectMap[$key]) || !isset($meeting['section_subject_id'])
            || (int)$meeting['section_subject_id'] !== $subjectMap[$key]) {
            rgReject(422, 'INVALID_SECTION_SUBJECT', 'Meeting has a missing or mismatched section_subject_id.');
        }
        $mode = $meeting['delivery_mode'];
        if (!in_array($mode, ['F2F', 'ONLINE'], true)) { rgReject(422, 'INVALID_MODE', 'Invalid meeting delivery mode.'); }
        $uniqueKey = $subjectMap[$key] . ':' . $mode;
        if (isset($unique[$uniqueKey])) { rgReject(422, 'DUPLICATE_MEETING', 'A meeting occurs more than once.'); }
        $unique[$uniqueKey] = true;
        $teacher = (int)$meeting['teacher_id'];
        $room = $meeting['room_id'] === null ? null : (int)$meeting['room_id'];
        $day = (string)$meeting['day_of_week'];
        if (!in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], true)) {
            rgReject(422, 'INVALID_DAY', 'Invalid meeting day.');
        }
        $start = rgMinutes((string)$meeting['start_time']);
        $end = rgMinutes((string)$meeting['end_time']);
        if ($start >= $end) { rgReject(422, 'INVALID_TIME', 'Invalid meeting interval.'); }
        // Second explicit check against ONLY other programs; the old BSIT batch is excluded.
        foreach ($otherRows as $saved) {
            if ($day !== $saved['day_of_week']) { continue; }
            $oldStart = rgMinutes((string)$saved['start_time']);
            $oldEnd = rgMinutes((string)$saved['end_time']);
            if ($start >= $oldEnd || $oldStart >= $end) { continue; }
            if ($teacher === (int)$saved['teacher_id'] || $section === (int)$saved['section_id']
                || $subject === (int)$saved['subject_id']
                || ($room !== null && $saved['room_id'] !== null && $room === (int)$saved['room_id'])) {
                rgReject(409, 'SAVED_SCHEDULE_CONFLICT', 'New meeting conflicts with another program’s saved schedule.');
            }
        }
        // Saved semester F2F room must survive regeneration.
        $locks = $fresh['scheduling_input']['replacement_room_locks'] ?? [];
        $requiredRoom = $locks[$section] ?? $locks[(string)$section] ?? null;
        if ($mode === 'F2F' && $requiredRoom !== null && $room !== (int)$requiredRoom) {
            rgReject(422, 'SEMESTER_ROOM_CHANGED', 'Replacement changed a locked section room.');
        }
        $newMeetings[] = [
            'section_subject_id' => $subjectMap[$key], 'teacher_id' => $teacher,
            'room_id' => $room, 'mode' => $mode, 'day' => $day,
            'start_time' => rgDbTime((string)$meeting['start_time']),
            'end_time' => rgDbTime((string)$meeting['end_time']),
        ];
    }
    if (count($newMeetings) !== (int)$result['required_meetings']) {
        rgReject(422, 'INCOMPLETE_PREVIEW', 'Replacement does not contain every meeting.');
    }
    // All changes happen in ONE transaction: insert complete new batch, supersede old batch.
    $stmt = $pdo->prepare("INSERT INTO schedule_batches
        (academic_period_id, program_id, data_origin, status)
        VALUES (:period_id, :program_id, 'DEMO', 'ACTIVE')");
    $stmt->execute(['period_id' => $periodId, 'program_id' => $programId]);
    $newBatch = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO schedule_meetings
        (batch_id, section_subject_id, teacher_id, room_id, delivery_mode, day_of_week, start_time, end_time)
        VALUES (:batch_id, :section_subject_id, :teacher_id, :room_id, :mode, :day, :start_time, :end_time)');
    foreach ($newMeetings as $item) { $stmt->execute(['batch_id' => $newBatch] + $item); }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schedule_meetings WHERE batch_id = :batch_id');
    $stmt->execute(['batch_id' => $newBatch]);
    if ((int)$stmt->fetchColumn() !== count($newMeetings)) {
        rgReject(500, 'PARTIAL_INSERT', 'Inserted meeting count mismatch; rolling back.');
    }
    $stmt = $pdo->prepare("UPDATE schedule_batches SET status = 'SUPERSEDED'
        WHERE batch_id = :batch_id AND academic_period_id = :period_id
        AND program_id = :program_id AND data_origin = 'DEMO' AND status = 'ACTIVE'");
    $stmt->execute(['batch_id' => $oldBatch, 'period_id' => $periodId, 'program_id' => $programId]);
    if ($stmt->rowCount() !== 1) { rgReject(409, 'BASELINE_CHANGED', 'Old batch could not be superseded. Rolling back.'); }
    $pdo->commit();
    $committed = true;
    $http = 200;
    $out = [
        'success' => true, 'status' => 'DEMO_SCHEDULE_REPLACED',
        'old_batch_id' => $oldBatch, 'new_batch_id' => $newBatch,
        'old_status' => 'SUPERSEDED', 'new_status' => 'ACTIVE',
        'saved_meetings' => count($newMeetings), 'database_write' => true,
        'school_wide_validation_complete' => false,
        'message' => 'DEMO replacement committed. Official school validation remains pending.',
    ];
    try {
        rgSession();
        if (hash_equals((string)($_SESSION['bcp_replacement_preview']['token'] ?? ''), $req['replace_token'])) {
            unset($_SESSION['bcp_replacement_preview']);
        }
        session_write_close();
    } catch (Throwable $e) { error_log('Replacement saved; token cleanup: ' . $e->getMessage()); }
} catch (ReplacementRejected $e) {
    $http = $e->http;
    $out = ['success' => false, 'status' => $e->codeName,
        'message' => $e->getMessage(), 'database_write' => $committed];
} catch (Throwable $e) {
    error_log('BCP replacement-save failed: ' . $e->getMessage());
    $http = 500;
    $out = [
        'success' => false, 'status' => 'REPLACEMENT_FAILED',
        'message' => $committed ? 'Replacement committed, but a later operation failed; refresh the saved timetable.'
            : 'Replacement was not committed. Original timetable remains ACTIVE. Check PHP logs.',
        'database_write' => $committed,
    ];
} finally {
    if ($pdo instanceof PDO) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e) { error_log($e->getMessage()); } }
        if ($hasLock) {
            try {
                $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $stmt->execute(['lock_name' => $lockName]);
            } catch (Throwable $e) { error_log($e->getMessage()); }
        }
    }
}
rgRespond($http, $out);
