<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/python.php';
/**
 * BCP AUTOMATIC CLASS SCHEDULING SYSTEM
 *
 * Phase 3B - Generate Demo Schedule
 *
 * PHP -> Scheduling Input -> Python OR-Tools
 *
 * Preview only. No database writes.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

set_time_limit(180);

/**
 * Return an error response.
 */
function respondError(
    int $httpStatus,
    string $status,
    string $message,
    array $details = []
): void {

    http_response_code($httpStatus);

    echo json_encode(
        array_merge(
            [
                'success' => false,
                'status' => $status,
                'message' => $message,
                'database_write' => false,
            ],
            $details
        ),
        JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


/**
 * Send an HTTP request to a local API.
 */
function callLocalApi(
    string $url,
    ?array $postData = null,
    int $timeout = 30
): array {

    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException(
            'Unable to initialize HTTP request.'
        );
    }

    $options = [

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_CONNECTTIMEOUT => 5,

        CURLOPT_TIMEOUT => $timeout,

        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],

    ];

    if ($postData !== null) {

        $json = json_encode(
            $postData,
            JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
        );

        $options[CURLOPT_POST] = true;

        $options[CURLOPT_POSTFIELDS] = $json;

        $options[CURLOPT_HTTPHEADER] = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
    }

    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);

    $httpStatus = (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    $curlError = curl_error($curl);

    curl_close($curl);

    if ($response === false) {

        throw new RuntimeException(
            'Local API connection failed: '
                . $curlError
        );
    }

    $decoded = json_decode(
        $response,
        true
    );

    if (!is_array($decoded)) {

        throw new RuntimeException(
            'Local API returned an invalid JSON response.'
        );
    }

    return [
        'http_status' => $httpStatus,
        'data' => $decoded,
    ];
}


// ============================================
// 1. CHECK PHP CURL EXTENSION
// ============================================

if (!function_exists('curl_init')) {

    respondError(
        500,
        'CURL_UNAVAILABLE',
        'The PHP cURL extension is not enabled.'
    );
}


// ============================================
// 2. VALIDATE REQUEST PARAMETERS
// ============================================

$programCode = strtoupper(trim(
    (string) ($_GET['program'] ?? 'BSIT')
));

$academicYear = trim(
    (string) (
        $_GET['academic_year'] ?? '2026-2027'
    )
);

$semester = filter_var(
    $_GET['semester'] ?? 1,
    FILTER_VALIDATE_INT
);

if (
    !preg_match('/^[A-Z0-9]{2,30}$/', $programCode)
    || !preg_match(
        '/^[0-9]{4}-[0-9]{4}$/',
        $academicYear
    )
    || !in_array($semester, [1, 2], true)
) {

    respondError(
        400,
        'INVALID_PARAMETERS',
        'Invalid program, academic year, or semester.'
    );
}


// ============================================
// 3. LOAD SCHEDULING INPUT DIRECTLY
// ============================================

$_GET['program'] = $programCode;
$_GET['academic_year'] = $academicYear;
$_GET['semester'] = (string) $semester;

ob_start();

try {

    require __DIR__ . '/scheduling-input.php';

    $rawInput = ob_get_clean();
} catch (Throwable $exception) {

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    error_log(
        'BCP input loader: '
            . $exception->getMessage()
    );

    respondError(
        500,
        'INPUT_LOAD_FAILED',
        'Unable to load scheduling input.'
    );
}

$input = json_decode(
    $rawInput,
    true
);

if (!is_array($input)) {

    respondError(
        500,
        'INPUT_INVALID_JSON',
        'Scheduling input returned invalid JSON.',
        [
            'raw_response' => $rawInput,
        ]
    );
}

if (
    ($input['success'] ?? false) !== true
    || ($input['status'] ?? '')
    !== 'BASIC_INPUT_READY'
    || ($input['data_origin'] ?? '')
    !== 'DEMO'
) {

    respondError(
        422,
        'INPUT_VALIDATION_FAILED',
        'Scheduling inputs are not ready.',
        [
            'validation_errors' =>
            $input['validation_errors'] ?? [],
        ]
    );
}


// ============================================
// 5. VERIFY REQUIRED INPUT ARRAYS
// ============================================

$requiredInputs = [
    'sections',
    'section_subjects',
    'teachers',
    'authorizations',
    'teacher_availability',
    'rooms',
    'room_availability',
    'time_slots',
    'major_links',
];

$schedulingInput = $input['scheduling_input'] ?? null;

if (!is_array($schedulingInput)) {

    respondError(
        422,
        'INVALID_SCHEDULING_INPUT',
        'Scheduling input data is missing.'
    );
}

foreach ($requiredInputs as $key) {

    if (
        !isset($schedulingInput[$key])
        || !is_array($schedulingInput[$key])
    ) {

        respondError(
            422,
            'INVALID_SCHEDULING_INPUT',
            "Missing or invalid scheduling input: {$key}"
        );
    }
}


// ============================================
// 6. CREATE BACKGROUND PYTHON JOB
// ============================================

$pythonUrl = pythonBaseUrl()
    . '/api/schedules/jobs';

try {

    $pythonResponse = callLocalApi(
        $pythonUrl,
        $input,
        15
    );
} catch (Throwable $exception) {

    error_log(
        'BCP Python job submission: '
            . $exception->getMessage()
    );

    respondError(
        502,
        'PYTHON_CONNECTION_FAILED',
        'Unable to start the Python scheduling job.'
    );
}

$jobResponse = $pythonResponse['data'];


// ============================================
// 7. VERIFY JOB CREATION
// ============================================

if (
    $pythonResponse['http_status'] !== 200
    || ($jobResponse['success'] ?? false) !== true
    || ($jobResponse['status'] ?? '')
    !== 'SCHEDULE_JOB_QUEUED'
    || !is_string($jobResponse['job_id'] ?? null)
) {

    respondError(
        502,
        'PYTHON_JOB_CREATE_FAILED',
        'Python could not create the scheduling job.',
        [
            'python_http_status' =>
            $pythonResponse['http_status'],

            'python_response' =>
            $jobResponse,
        ]
    );
}

$jobId = $jobResponse['job_id'];


// ============================================
// 8. STORE INPUT FOR FINAL PREVIEW ESCROW
// ============================================

try {

    ini_set('session.use_strict_mode', '1');

    session_name('BCP_SCHED_DEMO');

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' =>
        !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'path' => '/',
    ]);

    if (session_status() !== PHP_SESSION_ACTIVE) {

        if (!session_start()) {

            throw new RuntimeException(
                'Cannot start scheduling job session.'
            );
        }
    }

    if (
        !isset($_SESSION['bcp_schedule_jobs'])
        || !is_array($_SESSION['bcp_schedule_jobs'])
    ) {

        $_SESSION['bcp_schedule_jobs'] = [];
    }

    $_SESSION['bcp_schedule_jobs'][$jobId] = [
        'created_at' => time(),
        'input' => $input,
    ];

    session_write_close();
} catch (Throwable $exception) {

    error_log(
        'BCP job session storage failed: '
            . $exception->getMessage()
    );

    respondError(
        500,
        'JOB_SESSION_FAILED',
        'Scheduling job started but its preview context could not be stored.'
    );
}


// ============================================
// 9. RETURN IMMEDIATELY
// ============================================

echo json_encode(
    [
        'success' => true,
        'status' => 'SCHEDULE_JOB_QUEUED',

        'job_id' => $jobId,

        'job_status' =>
        $jobResponse['job_status']
            ?? 'QUEUED',

        'database_write' => false,
    ],
    JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
);
