<?php
declare(strict_types=1);
/**
 * BCP Phase 4F — preview-only replacement of one BSIT DEMO ACTIVE batch.
 * Never saves, updates, supersedes or deletes ANY database record.
 * The existing generate.php and save-schedule.php remain unchanged.
 */
require_once dirname(__DIR__) . '/shared/auth.php';
authRequire(true);

require_once __DIR__ . '/../config/python.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(190);

function replacementPreviewFail(int $http, string $status, string $message): never
{
    http_response_code($http);
    echo json_encode([
        'success' => false, 'status' => $status,
        'message' => $message, 'database_write' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function replacementPreviewHttp(string $url, ?array $body, int $seconds): array
{
    $h = curl_init($url);
    if ($h === false) { throw new RuntimeException('Cannot start internal request.'); }
    $options = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $seconds,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if ($body !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/json'];
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($h, $options);
    $raw = curl_exec($h);
    $status = (int)curl_getinfo($h, CURLINFO_HTTP_CODE);
    $error = curl_error($h);
    curl_close($h);
    if ($raw === false) { throw new RuntimeException('Internal API connection failed: ' . $error); }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) { throw new RuntimeException('Internal API did not return a JSON object.'); }
    return [$status, $decoded];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    replacementPreviewFail(405, 'GET_ONLY', 'Preview endpoint accepts GET only.');
}
if (!function_exists('curl_init')) {
    replacementPreviewFail(500, 'CURL_UNAVAILABLE', 'PHP cURL is required.');
}
$program = strtoupper(trim((string)($_GET['program'] ?? '')));
$year = (string)($_GET['academic_year'] ?? '');
$semester = filter_var($_GET['semester'] ?? null, FILTER_VALIDATE_INT);
$batchId = filter_var($_GET['batch_id'] ?? null, FILTER_VALIDATE_INT);
if ($program !== 'BSIT' || !preg_match('/^\d{4}-\d{4}$/', $year)
    || !in_array($semester, [1, 2], true) || $batchId === false || $batchId === null || $batchId < 1) {
    replacementPreviewFail(400, 'INVALID_PARAMETERS', 'Specify BSIT, academic_year, semester and an ACTIVE batch_id.');
}

try {
    $query = http_build_query([
        'program' => $program, 'academic_year' => $year,
        'semester' => $semester, 'batch_id' => $batchId,
    ]);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? '';

    [$inputStatus, $input] = replacementPreviewHttp(
        $scheme . '://' . $host . '/app/api/replacement-input.php?' . $query,
        null,
        35
    );
    if ($inputStatus !== 200 || ($input['success'] ?? false) !== true
        || ($input['status'] ?? '') !== 'BASIC_INPUT_READY'
        || ($input['replacement_preview_only'] ?? false) !== true
        || (int)($input['replacement_baseline']['batch_id'] ?? 0) !== $batchId) {
        replacementPreviewFail(409, 'REPLACEMENT_INPUT_NOT_READY',
            (string)($input['message'] ?? 'The selected ACTIVE batch or scheduling inputs are not ready.'));
    }
    [$pythonStatus, $result] = replacementPreviewHttp(
        pythonBaseUrl() . '/api/schedules/preview',
        $input,
        155
    );
    if ($pythonStatus !== 200 || ($result['success'] ?? false) !== true
        || ($result['status'] ?? '') !== 'DEMO_PREVIEW_GENERATED'
        || ($result['audit']['passed'] ?? false) !== true
        || ($result['existing_snapshot_constraints_applied'] ?? false) !== true
        || !is_array($result['assignments'] ?? null)
        || count($result['assignments']) !== (int)($result['required_meetings'] ?? -1)) {
        replacementPreviewFail(422, 'REPLACEMENT_PREVIEW_FAILED',
            (string)($result['message'] ?? 'Python did not return a complete audited replacement preview.'));
    }

    // Explicitly not a Phase 4C save-token preview; no session escrow is set.
    $result['replacement_preview'] = true;
    $result['replacement_batch_id'] = $batchId;
    $result['old_batch_meetings'] = $input['replacement_baseline']['saved_meetings'];
    $result['fixed_other_program_meetings'] = count($input['scheduling_input']['existing_meetings']);
    $result['save_ready_demo'] = false;
    unset($result['save_token']);
    $result['database_write'] = false;
    $result['school_wide_validation_complete'] = false;
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('BCP replacement-preview failed: ' . $error->getMessage());
    replacementPreviewFail(502, 'REPLACEMENT_PREVIEW_UNAVAILABLE',
        'Preview could not be completed. Original ACTIVE timetable remains unchanged. Check PHP/Python logs.');
}
