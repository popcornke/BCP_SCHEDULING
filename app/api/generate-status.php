<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/config/python.php';

header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store'
);


function jobReply(
    array $payload,
    int $httpStatus = 200
): never {

    http_response_code(
        $httpStatus
    );

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


function jobHttpGet(
    string $url,
    int $timeout = 10
): array {

    $curl = curl_init(
        $url
    );

    if ($curl === false) {

        throw new RuntimeException(
            'Unable to initialize Python job request.'
        );
    }

    curl_setopt_array(
        $curl,
        [
            CURLOPT_RETURNTRANSFER =>
            true,

            CURLOPT_CONNECTTIMEOUT =>
            5,

            CURLOPT_TIMEOUT =>
            $timeout,

            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]
    );

    $response = curl_exec(
        $curl
    );

    $httpStatus = (int)
    curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    $error = curl_error(
        $curl
    );

    curl_close(
        $curl
    );

    if ($response === false) {

        throw new RuntimeException(
            'Python job status request failed: '
                . $error
        );
    }

    $decoded = json_decode(
        $response,
        true
    );

    if (!is_array($decoded)) {

        throw new RuntimeException(
            'Python job status returned invalid JSON.'
        );
    }

    return [
        'http_status' =>
        $httpStatus,

        'data' =>
        $decoded,
    ];
}


// ============================================
// 1. VALIDATE JOB ID
// ============================================

$jobId = trim(
    (string) (
        $_GET['job_id']
        ?? ''
    )
);

if (
    !preg_match(
        '/^[a-f0-9-]{36}$/i',
        $jobId
    )
) {

    jobReply(
        [
            'success' => false,
            'status' =>
            'INVALID_JOB_ID',
            'message' =>
            'Invalid scheduling job ID.',
        ],
        400
    );
}


// ============================================
// 2. LOAD ORIGINAL INPUT FROM SESSION
// ============================================

ini_set(
    'session.use_strict_mode',
    '1'
);

session_name(
    'BCP_SCHED_DEMO'
);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' =>
    !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
    'path' => '/',
]);

if (!session_start()) {

    jobReply(
        [
            'success' => false,
            'status' =>
            'SESSION_FAILED',
            'message' =>
            'Unable to load scheduling job session.',
        ],
        500
    );
}

$jobContext =
    $_SESSION['bcp_schedule_jobs'][$jobId]
    ?? null;

session_write_close();

if (!is_array($jobContext)) {

    jobReply(
        [
            'success' => false,
            'status' =>
            'JOB_CONTEXT_NOT_FOUND',
            'message' =>
            'Scheduling job context was not found.',
        ],
        404
    );
}

$input = $jobContext['input'] ?? null;

if (!is_array($input)) {

    jobReply(
        [
            'success' => false,
            'status' =>
            'INVALID_JOB_CONTEXT',
            'message' =>
            'Scheduling input context is invalid.',
        ],
        500
    );
}


// ============================================
// 3. CHECK PYTHON JOB
// ============================================

$pythonUrl = pythonBaseUrl()
    . '/api/schedules/jobs/'
    . rawurlencode(
        $jobId
    );

try {

    $pythonResponse = jobHttpGet(
        $pythonUrl,
        10
    );
} catch (Throwable $exception) {

    error_log(
        'BCP Python job status: '
            . $exception->getMessage()
    );

    jobReply(
        [
            'success' => false,
            'status' =>
            'PYTHON_JOB_STATUS_FAILED',
            'message' =>
            'Unable to check scheduling job status.',
        ],
        502
    );
}

if (
    $pythonResponse['http_status'] !== 200
) {

    jobReply(
        [
            'success' => false,
            'status' =>
            'PYTHON_JOB_STATUS_ERROR',
            'message' =>
            'Python scheduling job status returned an error.',
            'python_response' =>
            $pythonResponse['data'],
        ],
        502
    );
}

$job = $pythonResponse['data'];

$jobStatus = (string) (
    $job['job_status']
    ?? ''
);


// ============================================
// 4. STILL RUNNING
// ============================================

if (
    $jobStatus === 'QUEUED'
    || $jobStatus === 'RUNNING'
) {

    jobReply(
        [
            'success' => true,
            'status' =>
            'SCHEDULE_JOB_RUNNING',

            'job_id' =>
            $jobId,

            'job_status' =>
            $jobStatus,

            'database_write' =>
            false,
        ]
    );
}


// ============================================
// 5. BACKGROUND JOB FAILED
// ============================================

if ($jobStatus === 'FAILED') {

    jobReply(
        [
            'success' => false,
            'status' =>
            'SCHEDULE_JOB_FAILED',

            'message' =>
            $job['error']
                ?? 'Scheduling job failed.',

            'database_write' =>
            false,
        ]
    );
}


// ============================================
// 6. VERIFY COMPLETED RESULT
// ============================================

if ($jobStatus !== 'COMPLETED') {

    jobReply(
        [
            'success' => false,
            'status' =>
            'UNKNOWN_JOB_STATUS',

            'message' =>
            'Unexpected scheduling job status.',
        ],
        500
    );
}

$result = $job['result'] ?? null;

if (!is_array($result)) {

    jobReply(
        [
            'success' => false,
            'status' =>
            'INVALID_JOB_RESULT',

            'message' =>
            'Scheduling job returned no result.',
        ],
        500
    );
}

$schedulingInput =
    $input['scheduling_input'] ?? null;

if (!is_array($schedulingInput)) {

    jobReply(
        [
            'success' => false,
            'status' =>
            'INVALID_SCHEDULING_INPUT',

            'message' =>
            'Original scheduling input is missing.',
        ],
        500
    );
}


// ============================================
// 7. VERIFY COMPLETE PREVIEW
// ============================================

if (
    ($result['success'] ?? false)
    === true
) {

    $expectedMeetings = count(
        $schedulingInput['section_subjects']
    ) * 2;

    $returnedMeetings = count(
        $result['assignments'] ?? []
    );

    if (
        ($result['status'] ?? '')
        !== 'DEMO_PREVIEW_GENERATED'

        || $returnedMeetings
        !== $expectedMeetings

        || (int) (
            $result['returned_meetings'] ?? -1
        ) !== $expectedMeetings
    ) {

        jobReply(
            [
                'success' => false,
                'status' =>
                'INCOMPLETE_PREVIEW',

                'message' =>
                'Python returned an incomplete scheduling preview.',

                'expected_meetings' =>
                $expectedMeetings,

                'returned_meetings' =>
                $returnedMeetings,
            ],
            422
        );
    }
}


// ============================================
// 8. HANDLE SOLVER FAILURE
// ============================================

if (
    ($result['success'] ?? false)
    !== true
) {

    jobReply(
        $result,
        200
    );
}


// ============================================
// 9. PREPARE SAVE ESCROW
// ============================================

$result['database_write'] = false;

$result['school_wide_validation_complete'] = false;

$result['save_ready_demo'] = false;

if (
    ($result['status'] ?? '')
    === 'DEMO_PREVIEW_GENERATED'

    && (
        $result['audit']['passed']
        ?? false
    ) === true

    && (
        $result['existing_snapshot_constraints_applied']
        ?? false
    ) === true

    && (
        $input['data_origin']
        ?? ''
    ) === 'DEMO'
) {

    try {

        if (!session_start()) {

            throw new RuntimeException(
                'Cannot reopen preview session.'
            );
        }

        $token = bin2hex(
            random_bytes(32)
        );

        $_SESSION['bcp_schedule_preview'] = [
            'token' =>
            $token,

            'created_at' =>
            time(),

            'program' =>
            $input['program'],

            'academic_period' =>
            $input['academic_period'],

            'input_hash' =>
            hash(
                'sha256',
                json_encode(
                    $input['scheduling_input'],
                    JSON_UNESCAPED_UNICODE
                        | JSON_INVALID_UTF8_SUBSTITUTE
                        | JSON_THROW_ON_ERROR
                )
            ),

            'result' =>
            $result,
        ];

        unset(
            $_SESSION['bcp_schedule_jobs'][$jobId]
        );

        session_write_close();

        $result['save_token'] = $token;

        $result['save_ready_demo'] = true;
    } catch (Throwable $exception) {

        error_log(
            'BCP preview escrow failed: '
                . $exception->getMessage()
        );

        $result['save_ready_demo'] = false;
    }
}


// ============================================
// 10. RETURN COMPLETED PREVIEW
// ============================================

jobReply(
    $result
);
