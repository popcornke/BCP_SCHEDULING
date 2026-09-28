<?php

declare(strict_types=1);
/** Phase 4G: audited replacement PREVIEW; never writes to the database. */
require_once __DIR__ . '/replacement-common.php';
require_once __DIR__ . '/../config/python.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(210);
try {
    rgGuard('POST');
    $req = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($req) || ($req['program'] ?? '') !== 'BSIT') {
        rgReject(400, 'INVALID_SELECTION', 'Only BSIT DEMO replacement is supported.');
    }
    $year = (string)($req['academic_year'] ?? '');
    $semester = filter_var($req['semester'] ?? null, FILTER_VALIDATE_INT);
    $batchId = filter_var($req['batch_id'] ?? null, FILTER_VALIDATE_INT);
    if (
        !preg_match('/^\d{4}-\d{4}$/', $year) || !in_array($semester, [1, 2], true)
        || $batchId === false || $batchId === null || $batchId < 1
    ) {
        rgReject(400, 'INVALID_SELECTION', 'Select a valid academic period and ACTIVE batch.');
    }
    rgSession();
    // Invalidates any previous replacement preview in this session before solving.
    unset($_SESSION['bcp_replacement_preview']);
    session_write_close();
    [$http, $input] = rgCall(rgInputUrl($batchId, $year, $semester));
    if ($http !== 200) {
        rgReject(409, 'REPLACEMENT_INPUT_NOT_READY', (string)($input['message'] ?? 'Cannot load replacement inputs.'));
    }
    $programId = (int)($input['program']['program_id'] ?? 0);
    $periodId = (int)($input['academic_period']['academic_period_id'] ?? 0);
    rgVerifyInput($input, $batchId, $periodId, $programId);
    if (
        $periodId < 1 || $programId < 1 || ($input['academic_period']['academic_year'] ?? '') !== $year
        || (int)($input['academic_period']['semester'] ?? 0) !== $semester
    ) {
        rgReject(409, 'PERIOD_MISMATCH', 'Replacement period changed.');
    }
    [$pythonHttp, $result] = rgCall(pythonBaseUrl() . '/api/schedules/preview', $input, 160);
    if (
        $pythonHttp !== 200 || ($result['success'] ?? false) !== true
        || ($result['status'] ?? '') !== 'DEMO_PREVIEW_GENERATED'
        || ($result['audit']['passed'] ?? false) !== true
        || ($result['audit']['status'] ?? '') !== 'AUDIT_PASSED'
        || ($result['existing_snapshot_constraints_applied'] ?? false) !== true
        || (int)($result['fixed_existing_meetings'] ?? -1) !== count($input['scheduling_input']['existing_meetings'])
        || !is_array($result['assignments'] ?? null)
        || count($result['assignments']) !== count($input['scheduling_input']['section_subjects']) * 2
        || count($result['assignments']) !== (int)($result['required_meetings'] ?? -1)
        || (int)($result['returned_meetings'] ?? -1) !== count($result['assignments'])
    ) {
        rgReject(422, 'REPLACEMENT_PREVIEW_FAILED', 'Python did not return a complete independently audited DEMO replacement.');
    }
    // Set a session-bound token ONLY after a passing audit. Never accept assignments from the browser.
    $token = bin2hex(random_bytes(32));
    rgSession();
    $_SESSION['bcp_replacement_preview'] = [
        'token' => $token,
        'created_at' => time(),
        'batch_id' => $batchId,
        'program_id' => $programId,
        'period_id' => $periodId,
        'year' => $year,
        'semester' => $semester,
        'input_hash' => rgHash($input['scheduling_input']),
        'baseline_sha256' => $input['replacement_baseline']['baseline_sha256'],
        'baseline_count' => (int)$input['replacement_baseline']['saved_meetings'],
        'result' => $result,
    ];
    session_write_close();
    $result['replacement_preview'] = true;
    $result['replacement_batch_id'] = $batchId;
    $result['old_batch_meetings'] = (int)$input['replacement_baseline']['saved_meetings'];
    $result['fixed_other_program_meetings'] = count($input['scheduling_input']['existing_meetings']);
    $result['save_ready_demo'] = true;
    $result['replace_token'] = $token;
    $result['database_write'] = false;
    $result['school_wide_validation_complete'] = false;
    rgRespond(200, $result);
} catch (ReplacementRejected $e) {
    rgRespond($e->http, [
        'success' => false,
        'status' => $e->codeName,
        'message' => $e->getMessage(),
        'database_write' => false,
    ]);
} catch (Throwable $e) {
    error_log('BCP replacement-generate failed: ' . $e->getMessage());
    rgRespond(500, [
        'success' => false,
        'status' => 'REPLACEMENT_GENERATION_FAILED',
        'message' => 'Replacement preview failed. Existing ACTIVE timetable is unchanged. Check PHP/Python logs.',
        'database_write' => false,
    ]);
}
