<?php 
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = getDatabase();
    $programCode = trim((string) ($_GET['program'] ?? ''));

    $sql = "
        SELECT
            s.subject_id,
            s.subject_code,
            s.subject_title,
            s.units,
            s.f2f_hours,
            s.online_hours,
            s.year_level,
            s.semester,
            s.is_verified,
            p.program_id,
            p.program_code,
            p.program_name
        FROM subjects AS s
        INNER JOIN programs AS p
            ON p.program_id = s.program_id
        WHERE
            s.is_active = 1
            AND p.is_active = 1
    ";
    
    $params = [];
    
    if ($programCode !== '') {
        $sql .= " AND p.program_code = :program_code ";
        $params['program_code'] = $programCode;
    }
    
    $sql .= "
        ORDER BY
            p.program_code,
            s.year_level,
            s.semester,
            s.subject_code
    ";
    
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $subjects = $statement->fetchAll();
    
    echo json_encode([
        'success' => true,
        'total' => count($subjects),
        'subjects' => $subjects,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $exception) {
    http_response_code(500);
    error_log($exception->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load subjects from the database.',
    ]);
}