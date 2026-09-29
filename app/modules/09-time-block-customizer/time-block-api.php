<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';
authRequire(true);
/** BCP Module 9 / Phase 9A. Local read-only inventory; never changes time_slots or saved schedules. */
require_once __DIR__ . '/../../config/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function tbReply(int $http, array $data): never {
    http_response_code($http);
    echo json_encode($data + ['read_only' => true, 'database_write' => false,
        'editing_enabled' => false], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        tbReply(405, ['success' => false, 'status' => 'METHOD_NOT_ALLOWED', 'message' => 'GET only.']);
    }
    if (($_GET['action'] ?? 'catalog') !== 'catalog') {
        tbReply(400, ['success' => false, 'status' => 'INVALID_ACTION', 'message' => 'Use action=catalog.']);
    }

    $db = getDatabase();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // SHOW COLUMNS does not need access to information_schema (which may be restricted in XAMPP).
    $columns = $db->query('SHOW COLUMNS FROM `time_slots`')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($columns, 'Field');
    $order = [];
    foreach (['day_of_week', 'start_time', 'end_time'] as $field) {
        if (in_array($field, $names, true)) $order[] = '`' . $field . '`';
    }
    $sql = 'SELECT * FROM `time_slots`' . ($order ? ' ORDER BY ' . implode(', ', $order) : '') . ' LIMIT 10001';
    $slots = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $truncated = count($slots) > 10000;
    if ($truncated) array_pop($slots);

    $periods = $db->query("SELECT academic_period_id, academic_year, semester, period_status
        FROM academic_periods ORDER BY academic_period_id DESC")->fetchAll(PDO::FETCH_ASSOC);
    // Count only: no batch, meeting, student, teacher or other records are changed.
    $classCount = (int)$db->query("SELECT COUNT(*) FROM schedule_batches WHERE status='ACTIVE'")->fetchColumn();
    $examCount = (int)$db->query("SELECT COUNT(*) FROM exam_batches WHERE status='ACTIVE'")->fetchColumn();

    tbReply(200, [
        'success' => true, 'status' => 'TIME_BLOCK_INVENTORY_READY',
        'source_table' => 'time_slots', 'source_columns' => $columns,
        'columns' => $names, 'slots' => $slots, 'slot_count' => count($slots),
        'truncated' => $truncated, 'periods' => $periods,
        'saved_schedule_guard' => [
            'active_class_batches' => $classCount, 'active_exam_batches' => $examCount,
            'existing_times_protected' => true,
        ],
        'notice' => 'Inventory only. No time slot was created, disabled, deleted, or changed. '
            . 'Existing saved schedules remain untouched. Schema and school time policies must be checked before enabling changes.',
    ]);
} catch (Throwable $e) {
    error_log('BCP Module 9 time-block inventory: ' . $e->getMessage());
    tbReply(500, ['success' => false, 'status' => 'TIME_BLOCK_INVENTORY_ERROR',
        'message' => 'Unable to load time blocks. Check the Apache/PHP error log; no records were changed.']);
}
