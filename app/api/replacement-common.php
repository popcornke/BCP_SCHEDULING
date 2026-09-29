<?php
declare(strict_types=1);
/** Phase 4G shared helpers. Local-only DEMO integration, NOT production authorization. */
require_once dirname(__DIR__) . '/shared/auth.php';
authRequire(true);

require_once __DIR__ . '/../config/python.php';
require_once __DIR__ . '/../config/database.php';

final class ReplacementRejected extends RuntimeException {
    public function __construct(public readonly int $http, public readonly string $codeName, string $why) {
        parent::__construct($why);
    }
}
function rgReject(int $http, string $code, string $message): never {
    throw new ReplacementRejected($http, $code, $message);
}
function rgRespond(int $http, array $response): never {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function rgGuard(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        rgReject(405, 'WRONG_METHOD', "Only {$method} is allowed.");
    }
    if (!function_exists('curl_init')) {
        rgReject(500, 'CURL_UNAVAILABLE', 'PHP cURL is required.');
    }
    // Same-origin browser calls; not a substitute for login/roles in production.
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        $origin = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
        $host = strtolower((string) explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
        if (!is_string($origin) || strtolower($origin) !== $host) {
            rgReject(403, 'CROSS_ORIGIN_REQUEST', 'Cross-origin write request denied.');
        }
    }
}
function rgJson(array $item): string {
    return json_encode($item, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}
function rgHash(array $item): string { return hash('sha256', rgJson($item)); }
function rgSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    ini_set('session.use_strict_mode', '1');
    session_name('BCP_SCHED_DEMO');
    session_set_cookie_params([
        'httponly' => true, 'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'path' => '/',
    ]);
    if (!session_start()) { rgReject(500, 'SESSION_UNAVAILABLE', 'Cannot open DEMO session.'); }
}
function rgCall(string $url, ?array $post = null, int $timeout = 35): array {
    $curl = curl_init($url);
    if ($curl === false) { rgReject(502, 'API_UNAVAILABLE', 'Internal request could not start.'); }
    $options = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if ($post !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/json'];
        $options[CURLOPT_POSTFIELDS] = rgJson($post);
    }
    curl_setopt_array($curl, $options);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) {
        error_log('Replacement API connection: ' . $error);
        rgReject(502, 'API_UNAVAILABLE', 'Internal API connection failed.');
    }
    try { $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { rgReject(502, 'BAD_API_RESPONSE', 'Internal API returned invalid JSON.'); }
    if (!is_array($data)) { rgReject(502, 'BAD_API_RESPONSE', 'Internal API returned an invalid object.'); }
    return [$status, $data];
}
function rgInputUrl(int $batch, string $year, int $semester): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? '';

    return $scheme . '://' . $host
        . '/app/api/replacement-input.php?'
        . http_build_query([
            'program' => 'BSIT',
            'academic_year' => $year,
            'semester' => $semester,
            'batch_id' => $batch,
        ]);
}
function rgVerifyInput(array $input, int $batchId, int $periodId, int $programId): void {
    if (($input['success'] ?? false) !== true || ($input['status'] ?? '') !== 'BASIC_INPUT_READY'
        || ($input['data_origin'] ?? '') !== 'DEMO'
        || ($input['replacement_preview_only'] ?? false) !== true
        || (int) ($input['replacement_baseline']['batch_id'] ?? 0) !== $batchId
        || (int) ($input['replacement_baseline']['saved_meetings'] ?? 0) < 1
        || !is_string($input['replacement_baseline']['baseline_sha256'] ?? null)
        || (int) ($input['academic_period']['academic_period_id'] ?? 0) !== $periodId
        || (int) ($input['program']['program_id'] ?? 0) !== $programId
        || ($input['program']['program_code'] ?? '') !== 'BSIT'
        || ($input['academic_period']['period_status'] ?? '') !== 'DEMO'
        || !is_array($input['scheduling_input'] ?? null)
        || !is_array($input['scheduling_input']['existing_meetings'] ?? null)
        || !is_array($input['scheduling_input']['section_subjects'] ?? null)) {
        rgReject(409, 'REPLACEMENT_INPUT_NOT_READY', 'Current DEMO replacement inputs are unavailable or inconsistent.');
    }
}
function rgMinutes(string $value): int {
    if (!preg_match('/^(\d\d):(\d\d)(?::00)?$/', $value, $p)) {
        rgReject(422, 'INVALID_TIME', 'Invalid class time in preview.');
    }
    $h = (int)$p[1]; $m = (int)$p[2];
    if ($h > 23 || $m > 59) { rgReject(422, 'INVALID_TIME', 'Invalid class time in preview.'); }
    return $h * 60 + $m;
}
function rgDbTime(string $value): string {
    $n = rgMinutes($value);
    return sprintf('%02d:%02d:00', intdiv($n, 60), $n % 60);
}
function rgLockName(int $period): string { return 'BCP_SCHED_SAVE_PERIOD_' . $period; }
