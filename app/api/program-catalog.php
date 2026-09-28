<?php
declare(strict_types=1);
/** Phase 4D: read-only program/period catalog. Uses existing bcp_scheduling schema. */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function catalogResponse(int $http, array $body): never {
    http_response_code($http);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    catalogResponse(405, ['success' => false, 'message' => 'GET only.']);
}
try {
    $pdo = getDatabase();
    $periods = $pdo->query('SELECT academic_period_id, academic_year, semester, period_status FROM academic_periods ORDER BY academic_year DESC, semester DESC, academic_period_id DESC')->fetchAll(PDO::FETCH_ASSOC);
    if (!$periods) {
        catalogResponse(200, ['success' => true, 'periods' => [], 'selected_period' => null, 'programs' => []]);
    }
    $requested = isset($_GET['period_id']) ? filter_var($_GET['period_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
    if ($requested === false) {
        catalogResponse(400, ['success' => false, 'message' => 'Invalid academic period ID.']);
    }
    $selected = null;
    foreach ($periods as $period) {
        if ($requested === null || (int) $period['academic_period_id'] === $requested) {
            $selected = $period;
            break;
        }
    }
    if ($selected === null) {
        catalogResponse(404, ['success' => false, 'message' => 'Academic period not found.']);
    }
    $periodId = (int) $selected['academic_period_id'];
    $programs = $pdo->query("SELECT program_id, program_code, program_name, education_level FROM programs WHERE is_active = 1 AND education_level = 'College' ORDER BY program_code")->fetchAll(PDO::FETCH_ASSOC);
    $sectionsStmt = $pdo->prepare("SELECT program_id, COUNT(*) AS total FROM sections WHERE academic_period_id = :period_id AND data_origin = 'DEMO' AND is_active = 1 GROUP BY program_id");
    $sectionsStmt->execute(['period_id' => $periodId]);
    $sectionCounts = [];
    foreach ($sectionsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sectionCounts[(int) $row['program_id']] = (int) $row['total'];
    }
    $batchStmt = $pdo->prepare("SELECT b.program_id, COUNT(DISTINCT b.batch_id) AS batches, COUNT(m.meeting_id) AS meetings FROM schedule_batches b LEFT JOIN schedule_meetings m ON m.batch_id = b.batch_id WHERE b.academic_period_id = :period_id AND b.status = 'ACTIVE' GROUP BY b.program_id");
    $batchStmt->execute(['period_id' => $periodId]);
    $saved = [];
    foreach ($batchStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $saved[(int) $row['program_id']] = ['batches' => (int) $row['batches'], 'meetings' => (int) $row['meetings']];
    }
    foreach ($programs as &$program) {
        $id = (int) $program['program_id'];
        $count = $saved[$id]['batches'] ?? 0;
        $meetings = $saved[$id]['meetings'] ?? 0;
        $sections = $sectionCounts[$id] ?? 0;
        $program['program_id'] = $id;
        $program['demo_sections'] = $sections;
        $program['active_batch_count'] = $count;
        $program['saved_meetings'] = $meetings;
        $program['has_saved_schedule'] = $count === 1 && $meetings > 0;
        // Only BSIT has an implemented and tested program-specific solver configuration.
        $program['can_generate_demo'] = $program['program_code'] === 'BSIT'
            && $selected['period_status'] === 'DEMO'
            && $sections > 0 && $count === 0;
    }
    unset($program);
    catalogResponse(200, [
        'success' => true,
        'status' => 'PROGRAM_CATALOG_READY',
        'periods' => $periods,
        'selected_period' => $selected,
        'programs' => $programs,
        'database_write' => false,
    ]);
} catch (Throwable $exception) {
    error_log('BCP catalog: ' . $exception->getMessage());
    catalogResponse(500, ['success' => false, 'status' => 'CATALOG_FAILED', 'message' => 'Could not load program catalog.']);
}
