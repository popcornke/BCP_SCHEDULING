<?php
declare(strict_types=1);

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
// 3. PREPARE SCHEDULING INPUT URL
// ============================================

$query = http_build_query([
    'program' => $programCode,
    'academic_year' => $academicYear,
    'semester' => $semester,
]);

$inputUrl = (
    'http://127.0.0.1/BCP_SCHEDULING/'
    . 'app/api/scheduling-input.php?'
    . $query
);


// ============================================
// 4. LOAD DATABASE SCHEDULING INPUT
// ============================================

try {

    $inputResponse = callLocalApi(
        $inputUrl,
        null,
        30
    );

} catch (Throwable $exception) {

    error_log(
        'BCP input loader: '
        . $exception->getMessage()
    );

    respondError(
        502,
        'INPUT_CONNECTION_FAILED',
        'Unable to connect to the scheduling input API.'
    );
}

$input = $inputResponse['data'];

if ($inputResponse['http_status'] !== 200) {

    respondError(
        502,
        'INPUT_API_ERROR',
        'Scheduling input API returned an error.',
        [
            'input_response' => $input,
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
// 6. CALL PYTHON OR-TOOLS OPTIMIZER
// ============================================

$pythonUrl = (
    'http://127.0.0.1:8000/'
    . 'api/schedules/preview'
);

try {

    $pythonResponse = callLocalApi(
        $pythonUrl,
        $input,
        150
    );

} catch (Throwable $exception) {

    error_log(
        'BCP Python optimizer: '
        . $exception->getMessage()
    );

    respondError(
        502,
        'PYTHON_CONNECTION_FAILED',
        'Unable to communicate with the Python optimizer.'
    );
}

$result = $pythonResponse['data'];


// ============================================
// 7. HANDLE PYTHON HTTP ERRORS
// ============================================

if ($pythonResponse['http_status'] !== 200) {

    respondError(
        502,
        'PYTHON_API_ERROR',
        'Python optimizer returned an HTTP error.',
        [
            'python_http_status' =>
                $pythonResponse['http_status'],

            'python_response' => $result,
        ]
    );
}


// ============================================
// 8. VERIFY COMPLETE PREVIEW
// ============================================

if (($result['success'] ?? false) === true) {

    $expectedMeetings = count(
        $schedulingInput['section_subjects']
    ) * 2;

    $returnedMeetings = count(
        $result['assignments'] ?? []
    );

    if (
        ($result['status'] ?? '')
            !== 'DEMO_PREVIEW_GENERATED'
        || $returnedMeetings !== $expectedMeetings
        || (int) (
            $result['returned_meetings'] ?? -1
        ) !== $expectedMeetings
    ) {

        respondError(
            422,
            'INCOMPLETE_PREVIEW',
            'Python returned an incomplete scheduling preview.',
            [
                'expected_meetings' =>
                    $expectedMeetings,

                'returned_meetings' =>
                    $returnedMeetings,
            ]
        );
    }
}


// ============================================
// 9. RETURN PYTHON RESULT
// ============================================

// The returned schedule is a DEMO preview.
//
// No schedule is inserted into MySQL here.

$result['database_write'] = false;

$result['school_wide_validation_complete'] = false;

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE
    | JSON_INVALID_UTF8_SUBSTITUTE
);