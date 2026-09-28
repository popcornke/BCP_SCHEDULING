<?php
declare(strict_types=1);
/** Phase 4D: read-only saved timetable. Does NOT regenerate or change an ACTIVE batch. */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function savedResponse(int $http, array $body): never {
    http_response_code($http);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    savedResponse(405, ['success' => false, 'message' => 'GET only.']);
}
$programCode = strtoupper(trim((string) ($_GET['program'] ?? '')));
$year = trim((string) ($_GET['academic_year'] ?? ''));
$semester = filter_var($_GET['semester'] ?? null, FILTER_VALIDATE_INT);
if (!preg_match('/^[A-Z0-9]{2,30}$/', $programCode)
    || !preg_match('/^\d{4}-\d{4}$/', $year)
    || !in_array($semester, [1, 2], true)) {
    savedResponse(400, ['success' => false, 'status' => 'INVALID_SELECTION', 'message' => 'Choose a valid program, academic year, and semester.']);
}
try {
    $pdo = getDatabase();
    $stmt = $pdo->prepare('SELECT program_id, program_code, program_name FROM programs WHERE program_code = :code AND is_active = 1 LIMIT 1');
    $stmt->execute(['code' => $programCode]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$program) {
        savedResponse(404, ['success' => false, 'status' => 'PROGRAM_NOT_FOUND', 'message' => 'Program not found.']);
    }
    $stmt = $pdo->prepare('SELECT academic_period_id, academic_year, semester, period_status FROM academic_periods WHERE academic_year = :year AND semester = :semester LIMIT 1');
    $stmt->execute(['year' => $year, 'semester' => $semester]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        savedResponse(404, ['success' => false, 'status' => 'PERIOD_NOT_FOUND', 'message' => 'Academic period not found.']);
    }
    $stmt = $pdo->prepare("SELECT batch_id, data_origin, created_at FROM schedule_batches WHERE program_id = :program_id AND academic_period_id = :period_id AND status = 'ACTIVE' ORDER BY batch_id");
    $stmt->execute(['program_id' => $program['program_id'], 'period_id' => $period['academic_period_id']]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$batches) {
        savedResponse(200, [
            'success' => true, 'status' => 'NO_SAVED_SCHEDULE', 'has_saved_schedule' => false,
            'program' => $program, 'academic_period' => $period,
            'sections' => 0, 'saved_meetings' => 0, 'assignments' => [], 'database_write' => false,
        ]);
    }
    if (count($batches) !== 1) {
        savedResponse(409, ['success' => false, 'status' => 'MULTIPLE_ACTIVE_BATCHES', 'message' => 'More than one ACTIVE batch exists for this program and period. No timetable displayed.']);
    }
    $batch = $batches[0];
    $stmt = $pdo->prepare(<<<'SQL'
SELECT m.meeting_id, m.section_subject_id,
       sec.section_id, sec.section_code, sec.section_type, sec.program_id AS section_program_id,
       sec.academic_period_id AS section_period_id,
       s.subject_id, s.subject_code, s.subject_title, s.program_id AS subject_program_id,
       t.teacher_id, t.teacher_name, t.program_id AS teacher_program_id,
       m.room_id, r.room_name, m.delivery_mode, m.day_of_week, m.start_time, m.end_time
FROM schedule_meetings m
JOIN section_subjects ss ON ss.section_subject_id = m.section_subject_id
JOIN sections sec ON sec.section_id = ss.section_id
JOIN subjects s ON s.subject_id = ss.subject_id
JOIN teachers t ON t.teacher_id = m.teacher_id
LEFT JOIN rooms r ON r.room_id = m.room_id
WHERE m.batch_id = :batch_id
ORDER BY sec.year_level, sec.section_code,
         FIELD(m.delivery_mode, 'F2F', 'ONLINE'),
         FIELD(m.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
         m.start_time, m.meeting_id
SQL);
    $stmt->execute(['batch_id' => $batch['batch_id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        savedResponse(409, ['success' => false, 'status' => 'EMPTY_ACTIVE_BATCH', 'message' => 'ACTIVE schedule has no readable meetings.']);
    }
    $assignments = [];
    $sectionIds = [];
    foreach ($rows as $row) {
        // Fail closed if a saved row has been reassigned to another program/period.
        if ((int) $row['section_program_id'] !== (int) $program['program_id']
            || (int) $row['subject_program_id'] !== (int) $program['program_id']
            || (int) $row['teacher_program_id'] !== (int) $program['program_id']
            || (int) $row['section_period_id'] !== (int) $period['academic_period_id']
            || ($row['delivery_mode'] === 'F2F' && $row['room_id'] === null)
            || ($row['delivery_mode'] === 'ONLINE' && $row['room_id'] !== null)) {
            savedResponse(409, ['success' => false, 'status' => 'INCONSISTENT_SAVED_SCHEDULE', 'message' => 'Saved timetable references inconsistent records. Review database integrity.']);
        }
        $sectionIds[(int) $row['section_id']] = true;
        $start = substr((string) $row['start_time'], 0, 5);
        $end = substr((string) $row['end_time'], 0, 5);
        $startMinutes = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
        $endMinutes = ((int) substr($end, 0, 2)) * 60 + (int) substr($end, 3, 2);
        $assignments[] = [
            'meeting_id' => (int) $row['meeting_id'],
            'section_subject_id' => (int) $row['section_subject_id'],
            'section_id' => (int) $row['section_id'],
            'section_code' => $row['section_code'],
            'section_type' => $row['section_type'],
            'subject_id' => (int) $row['subject_id'],
            'subject_code' => $row['subject_code'],
            'subject_title' => $row['subject_title'],
            'teacher_id' => (int) $row['teacher_id'],
            'teacher_name' => $row['teacher_name'],
            'room_id' => $row['room_id'] === null ? null : (int) $row['room_id'],
            'room_name' => $row['room_name'],
            'delivery_mode' => $row['delivery_mode'],
            'day_of_week' => $row['day_of_week'],
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => $endMinutes - $startMinutes,
        ];
    }
    savedResponse(200, [
        'success' => true, 'status' => 'SAVED_SCHEDULE_LOADED', 'has_saved_schedule' => true,
        'program' => $program, 'academic_period' => $period,
        'batch' => ['batch_id' => (int) $batch['batch_id'], 'data_origin' => $batch['data_origin'], 'created_at' => $batch['created_at']],
        'sections' => count($sectionIds), 'saved_meetings' => count($assignments),
        'assignments' => $assignments, 'database_write' => false,
        'independent_audit_rerun' => false,
    ]);
} catch (Throwable $exception) {
    error_log('BCP saved timetable: ' . $exception->getMessage());
    savedResponse(500, ['success' => false, 'status' => 'SAVED_SCHEDULE_LOAD_FAILED', 'message' => 'Could not load the saved timetable.']);
}
