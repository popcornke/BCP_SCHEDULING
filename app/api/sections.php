<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {

    $pdo = getDatabase();

    $program = trim(
        (string) ($_GET['program'] ?? 'BSIT')
    );

    $academicYear = trim(
        (string) ($_GET['academic_year'] ?? '2026-2027')
    );

    $semester = filter_var(
        $_GET['semester'] ?? 1,
        FILTER_VALIDATE_INT
    );

    if (
        !preg_match('/^[A-Za-z0-9]{2,30}$/', $program)
        || !preg_match(
            '/^[0-9]{4}-[0-9]{4}$/',
            $academicYear
        )
        || !in_array($semester, [1, 2], true)
    ) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid scheduling filters.'
        ]);

        exit;
    }

    $sql = "

        SELECT

            sec.section_id,
            sec.section_code,
            sec.year_level,
            sec.section_type,
            sec.student_count,
            sec.data_origin,

            p.program_id,
            p.program_code,
            p.program_name,

            ap.academic_period_id,
            ap.academic_year,
            ap.semester,

            COUNT(ss.subject_id) AS subject_count

        FROM sections AS sec

        INNER JOIN programs AS p
            ON p.program_id = sec.program_id

        INNER JOIN academic_periods AS ap
            ON ap.academic_period_id =
               sec.academic_period_id

        LEFT JOIN section_subjects AS ss
            ON ss.section_id = sec.section_id

        WHERE p.program_code = :program

          AND ap.academic_year = :academic_year

          AND ap.semester = :semester

          AND sec.is_active = 1

        GROUP BY

            sec.section_id,
            sec.section_code,
            sec.year_level,
            sec.section_type,
            sec.student_count,
            sec.data_origin,

            p.program_id,
            p.program_code,
            p.program_name,

            ap.academic_period_id,
            ap.academic_year,
            ap.semester

        ORDER BY

            sec.year_level,
            sec.section_code

    ";

    $statement = $pdo->prepare($sql);

    $statement->execute([
        'program' => $program,
        'academic_year' => $academicYear,
        'semester' => $semester
    ]);

    $sections = $statement->fetchAll();

    echo json_encode(
        [
            'success' => true,

            'program' => $program,

            'academic_year' => $academicYear,

            'semester' => $semester,

            'total_sections' => count($sections),

            'sections' => $sections
        ],
        JSON_UNESCAPED_UNICODE
    );

} catch (Throwable $exception) {

    error_log($exception->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,

        'message' =>
            'Unable to retrieve scheduling sections.'
    ]);
}