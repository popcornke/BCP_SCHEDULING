<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/python.php';
// Trailing space removed from the URL
$pythonUrl = pythonBaseUrl() . '/api/health';

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'PHP cURL extension is unavailable.',
    ]);
    exit;
}

$curl = curl_init($pythonUrl);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($curl);
$error = curl_error($curl);
$statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($response === false || $statusCode !== 200) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to communicate with Python backend.',
        'python_http_status' => $statusCode,
        'connection_error' => $error ?: null,
    ]);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data)) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid response received from Python backend.',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'php_to_python' => 'connected',
    'python_response' => $data,
], JSON_UNESCAPED_UNICODE);
