<?php

declare(strict_types=1);
/** Module 4: Generate a server-held, audited DEMO preview. No DB writes. */
require_once __DIR__ . '/exam-common.php';
require_once __DIR__ . '/../../config/python.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        exGuard('GET');
        $pdo = getDatabase();
        $periods = exRows($pdo, "SELECT academic_period_id,academic_year,semester FROM academic_periods WHERE period_status='DEMO' ORDER BY academic_period_id DESC");
        $programs = exRows($pdo, "SELECT program_code,program_name FROM programs WHERE is_active=1 AND education_level='College' ORDER BY program_code");
        exReply(200, ['success' => true, 'status' => 'EXAM_CATALOG_READY', 'periods' => $periods, 'programs' => $programs, 'database_write' => false]);
    }
    exGuard('POST');
    set_time_limit(155);
    $body = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body) || !is_string($body['program'] ?? null)) exFail(400, 'PROGRAM_REQUIRED', 'Choose a program.');
    $programCode = strtoupper(trim((string)$body['program']));
    $periodId = filter_var($body['period_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$periodId || !is_array($body['exam_dates'] ?? null)) exFail(400, 'INVALID_SELECTION', 'Choose a period and three examination dates.');
    $pdo = getDatabase();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $snapshot = exBuildInput($pdo, (int)$periodId, $body['exam_dates'], (string)($body['exam_window'] ?? '06-21'), null, $programCode);
    foreach ($snapshot['active_exam_batches'] as $old) {
        if ((int)$old['program_id'] === (int)$snapshot['program']['program_id'] && $old['exam_label'] === 'DEMO-EXAM') {
            exFail(409, 'EXAM_ALREADY_SAVED', 'An ACTIVE ' . $programCode . ' DEMO exam timetable exists. View the saved exams; replacement is a separate workflow.');
        }
    }
    if (!function_exists('curl_init')) exFail(500, 'CURL_UNAVAILABLE', 'Enable PHP cURL to reach FastAPI.');
    $curl = curl_init(
        pythonBaseUrl() . '/api/exams/preview'
    );
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 130,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($snapshot['input'], JSON_THROW_ON_ERROR)
    ]);
    $raw = curl_exec($curl);
    $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($raw) || $http !== 200) {
        error_log('Exam solver: ' . $http . ' ' . $curlError);
        exFail(502, 'EXAM_SOLVER_UNAVAILABLE', 'Python exam preview failed; check FastAPI logs.');
    }
    $output = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($output)) exFail(502, 'INVALID_SOLVER_OUTPUT', 'Python returned invalid exam result.');
    if (($output['success'] ?? false) !== true) {
        exReply(422, $output + ['database_write' => false]);
    }
    exValidate($snapshot, $output);
    session_name('BCP_EXAM_DEMO');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/BCP_SCHEDULING/app/modules/04-exam-timetable-generator']);
    session_start();
    $token = bin2hex(random_bytes(32));
    $_SESSION['bcp_exam_preview'] = [
        'token' => $token,
        'created_at' => time(),
        'period_id' => (int)$periodId,
        'program' => $programCode,
        'dates' => $snapshot['input']['exam_dates'],
        'window' => (string)($body['exam_window'] ?? '06-21'),
        'class_batch_id' => $snapshot['class_batch_id'],
        'input_hash' => exJsonHash($snapshot),
        'result' => $output
    ];
    session_write_close();
    $output['period'] = $snapshot['period'];
    $output['program'] = $programCode;
    $output['program_name'] = $snapshot['program']['program_name'];
    $output['class_batch_id'] = $snapshot['class_batch_id'];
    $output['preview_token'] = $token;
    $output['save_available_demo'] = true;
    $output['database_write'] = false;
    exReply(200, $output);
} catch (ExamFailure $e) {
    exReply($e->http, ['success' => false, 'status' => $e->errorCode, 'message' => $e->getMessage(), 'database_write' => false]);
} catch (Throwable $e) {
    error_log('BCP exam preview: ' . $e->getMessage());
    exReply(500, ['success' => false, 'status' => 'EXAM_PREVIEW_ERROR', 'message' => 'Exam preview failed. Check PHP error log; nothing was saved.', 'database_write' => false]);
}
