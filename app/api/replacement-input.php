<?php
declare(strict_types=1);
/**
 * BCP Phase 4F — read-only BSIT DEMO replacement input.
 * Reuses the existing scheduling-input.php, without changing its normal
 * protection against a second ACTIVE batch.
 * Never writes to MySQL; never enables replacement saving.
 */
require_once dirname(__DIR__) . '/shared/auth.php';
authRequire(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function replacementInputFail(int $http, string $status, string $message): never
{
    http_response_code($http);
    echo json_encode([
        'success' => false, 'status' => $status,
        'message' => $message, 'database_write' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    replacementInputFail(405, 'GET_ONLY', 'Read-only GET endpoint.');
}
if (strtoupper(trim((string)($_GET['program'] ?? ''))) !== 'BSIT') {
    replacementInputFail(403, 'BSIT_DEMO_ONLY', 'BSIT DEMO only.');
}
$requestedBatch = filter_var($_GET['batch_id'] ?? null, FILTER_VALIDATE_INT);
if ($requestedBatch === false || $requestedBatch === null || $requestedBatch < 1) {
    replacementInputFail(400, 'BATCH_ID_REQUIRED', 'Specify the ACTIVE batch_id to preview a replacement.');
}

try {
    // The existing loader is read-only. Its normal ACTIVE-batch error is
    // intentionally preserved for all callers other than this preview route.
    ob_start();
    require __DIR__ . '/scheduling-input.php';
    $raw = ob_get_clean();
    $source = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($source) || !is_array($source['scheduling_input'] ?? null)) {
        replacementInputFail(409, 'SOURCE_NOT_READY', 'Scheduling input could not be read.');
    }
    $programId = (int)($source['program']['program_id'] ?? 0);
    $periodId = (int)($source['academic_period']['academic_period_id'] ?? 0);
    if ($programId < 1 || $periodId < 1 || ($source['program']['program_code'] ?? '') !== 'BSIT'
        || ($source['academic_period']['period_status'] ?? '') !== 'DEMO'
        || ($source['data_origin'] ?? '') !== 'DEMO') {
        replacementInputFail(403, 'BSIT_DEMO_ONLY', 'Invalid program or academic period.');
    }

    $errors = $source['validation_errors'] ?? null;
    if (!is_array($errors) || count($errors) !== 1
        || !is_string($errors[0])
        || !str_starts_with($errors[0], 'This program already has an ACTIVE timetable.')) {
        replacementInputFail(409, 'SOURCE_NOT_READY',
            'The original loader must report only the existing BSIT ACTIVE timetable; all other input errors must be fixed first.');
    }

    $pdo = getDatabase();
    $batchStmt = $pdo->prepare(
        "SELECT batch_id, program_id, data_origin FROM schedule_batches
         WHERE academic_period_id = :period AND program_id = :program AND status = 'ACTIVE'"
    );
    $batchStmt->execute(['period' => $periodId, 'program' => $programId]);
    $active = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($active) !== 1 || (int)$active[0]['batch_id'] !== $requestedBatch
        || $active[0]['data_origin'] !== 'DEMO') {
        replacementInputFail(409, 'BATCH_CHANGED', 'Expected BSIT ACTIVE batch is missing or changed. No preview generated.');
    }

    $snapshot = $source['scheduling_input']['existing_meetings'] ?? null;
    if (!is_array($snapshot)) {
        replacementInputFail(409, 'SNAPSHOT_MISSING', 'Existing schedule snapshot is missing.');
    }
    $otherPrograms = [];
    $ownMeetings = [];
    foreach ($snapshot as $row) {
        if ((int)$row['program_id'] === $programId) {
            if ((int)$row['batch_id'] !== $requestedBatch) {
                replacementInputFail(409, 'BATCH_CHANGED', 'Unexpected second BSIT saved batch.');
            }
            $ownMeetings[] = $row;
        } else {
            $otherPrograms[] = $row;
        }
    }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM schedule_meetings WHERE batch_id = :id');
    $countStmt->execute(['id' => $requestedBatch]);
    $count = (int)$countStmt->fetchColumn();
    if ($count < 1 || count($ownMeetings) !== $count) {
        replacementInputFail(409, 'INCOMPLETE_BASELINE', 'Current BSIT batch is incomplete in the input snapshot.');
    }

    // Only the selected program's old meetings are omitted from the
    // SOLVER SNAPSHOT. All other ACTIVE programs remain protected.
    // Preserve the semester room selected by the previous timetable for
    // Years 1–3 REGULAR sections and the fourth-year CLUSTER bundle.
    $sectionKinds = [];
    foreach ($source['scheduling_input']['sections'] as $section) {
        $sectionKinds[(int)$section['section_id']] = $section;
    }
    $roomLocks = [];
    foreach ($ownMeetings as $old) {
        if ($old['delivery_mode'] !== 'F2F') { continue; }
        $secId = (int)$old['section_id'];
        $sec = $sectionKinds[$secId] ?? null;
        if (!is_array($sec)) {
            replacementInputFail(409, 'INVALID_BASELINE_SECTION', 'Old timetable contains an unknown section.');
        }
        $requiresFixedRoom = (
            ($sec['section_type'] === 'REGULAR' && in_array((int)$sec['year_level'], [1, 2, 3], true))
            || ($sec['section_type'] === 'CLUSTER' && (int)$sec['year_level'] === 4)
        );
        if (!$requiresFixedRoom) { continue; }
        $room = (int)($old['room_id'] ?? 0);
        if ($room < 1 || (isset($roomLocks[$secId]) && $roomLocks[$secId] !== $room)) {
            replacementInputFail(409, 'INCONSISTENT_BASELINE_ROOM',
                'Saved REGULAR/CLUSTER section has missing or changing room. No replacement allowed.');
        }
        $roomLocks[$secId] = $room;
    }
    foreach ($sectionKinds as $secId => $sec) {
        if (($sec['section_type'] === 'REGULAR' && in_array((int)$sec['year_level'], [1, 2, 3], true))
            || ($sec['section_type'] === 'CLUSTER' && (int)$sec['year_level'] === 4)) {
            if (!isset($roomLocks[$secId])) {
                replacementInputFail(409, 'INCOMPLETE_BASELINE_ROOM', 'Section lacks a fixed semester F2F room.');
            }
        }
    }
    $source['scheduling_input']['replacement_room_locks'] = $roomLocks;
    $source['scheduling_input']['existing_meetings'] = $otherPrograms;
    $source['counts']['existing_meetings'] = count($otherPrograms);
    $source['validation_errors'] = [];
    $source['success'] = true;
    $source['status'] = 'BASIC_INPUT_READY';
    $source['replacement_baseline'] = [
        'batch_id' => $requestedBatch,
        'saved_meetings' => $count,
        'baseline_sha256' => hash('sha256', json_encode($ownMeetings,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)),
    ];
    $source['replacement_preview_only'] = true;
    $source['database_write'] = false;
    http_response_code(200);
    echo json_encode($source, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    error_log('BCP replacement-input failed: ' . $error->getMessage());
    replacementInputFail(500, 'REPLACEMENT_INPUT_FAILED', 'Unable to prepare read-only replacement input. Check PHP logs.');
}
