<?php

declare(strict_types=1);
/** BCP Class Scheduling — Module 3, ACTIVE DEMO saved schedule audit, READ ONLY. */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 2) . '/shared/auth.php';
require_once __DIR__ . '/conflict-engine.php';

authRequire(true, ['ADMIN', 'SCHEDULER']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function ccReply(int $http, array $body): never
{
    http_response_code($http);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') ccReply(405, ['success' => false, 'status' => 'METHOD_NOT_ALLOWED', 'message' => 'GET only.']);

$periodId = filter_var($_GET['period_id'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($periodId === false) ccReply(400, ['success' => false, 'status' => 'INVALID_PERIOD', 'message' => 'Choose a valid academic period.']);
try {
    $pdo = getDatabase();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $periodStmt = $pdo->prepare("SELECT academic_period_id,academic_year,semester,period_status FROM academic_periods WHERE academic_period_id=:pid AND period_status='DEMO' LIMIT 1");
    $periodStmt->execute(['pid' => $periodId]);
    $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
    if (!$period) ccReply(404, ['success' => false, 'status' => 'DEMO_PERIOD_NOT_FOUND', 'message' => 'DEMO academic period not found.']);

    // Never scope the underlying audit to one program: school-wide resource conflicts matter.
    $batchStmt = $pdo->prepare(<<<'SQL'
SELECT b.batch_id,b.program_id,p.program_code,
       (SELECT COUNT(*) FROM schedule_meetings m WHERE m.batch_id=b.batch_id) AS meeting_count
FROM schedule_batches b
JOIN programs p ON p.program_id=b.program_id
WHERE b.academic_period_id=:pid AND b.status='ACTIVE' AND b.data_origin='DEMO'
ORDER BY b.program_id,b.batch_id
SQL);
    $batchStmt->execute(['pid' => $periodId]);
    $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

    // LEFT JOIN keeps broken references visible as audit issues instead of silently hiding rows.
    $meetStmt = $pdo->prepare(<<<'SQL'
SELECT m.meeting_id,m.batch_id,b.academic_period_id,b.program_id AS batch_program_id,
       p.program_code, m.section_subject_id,
       ss.section_id, ss.subject_id,
       sec.section_code,sec.section_type,sec.program_id AS section_program_id,
       sec.academic_period_id AS section_period_id,sec.student_count AS section_student_count,
       subj.subject_code,subj.program_id AS subject_program_id,
       m.teacher_id,t.program_id AS teacher_program_id,
       m.room_id,r.program_id AS room_program_id,r.capacity AS room_capacity,
       m.delivery_mode,m.day_of_week,
       TIME_FORMAT(m.start_time,'%H:%i') AS start_time,
       TIME_FORMAT(m.end_time,'%H:%i') AS end_time
FROM schedule_meetings m
JOIN schedule_batches b ON b.batch_id=m.batch_id
JOIN programs p ON p.program_id=b.program_id
LEFT JOIN section_subjects ss ON ss.section_subject_id=m.section_subject_id
LEFT JOIN sections sec ON sec.section_id=ss.section_id
LEFT JOIN subjects subj ON subj.subject_id=ss.subject_id
LEFT JOIN teachers t ON t.teacher_id=m.teacher_id
LEFT JOIN rooms r ON r.room_id=m.room_id
WHERE b.academic_period_id=:pid AND b.status='ACTIVE' AND b.data_origin='DEMO'
ORDER BY m.meeting_id
SQL);
    $meetStmt->execute(['pid' => $periodId]);
    $meetings = $meetStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($meetings) > 10000) ccReply(413, ['success' => false, 'status' => 'AUDIT_TOO_LARGE', 'message' => 'More than 10,000 saved meetings; run the scalable audit service instead.']);

    // Each saved ACTIVE program must contain exactly F2F and Online per required section-subject
    // for THIS DEMO scheduling policy. This does not make unconfigured programs schedulable.
    $expectedStmt = $pdo->prepare(<<<'SQL'
SELECT b.batch_id,ss.section_subject_id
FROM schedule_batches b
JOIN sections sec ON sec.program_id=b.program_id
   AND sec.academic_period_id=b.academic_period_id
JOIN section_subjects ss ON ss.section_id=sec.section_id
WHERE b.academic_period_id=:pid AND b.status='ACTIVE' AND b.data_origin='DEMO'
  AND sec.is_active=1 AND sec.data_origin='DEMO'
ORDER BY b.batch_id,ss.section_subject_id
SQL);
    $expectedStmt->execute(['pid' => $periodId]);
    $expected = $expectedStmt->fetchAll(PDO::FETCH_ASSOC);

    $studentAvailable = true;
    $membership = [];
    try {
        $memberStmt = $pdo->prepare("SELECT student_id,home_section_id,major_section_id,program_id FROM students WHERE academic_period_id=:pid AND data_origin='DEMO'");
        $memberStmt->execute(['pid' => $periodId]);
        $membership = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Missing students table means a PARTIAL result; other SQL errors must not be ignored.
        if ($e->getCode() === '42S02' || (string)$e->errorInfo[1] === '1146') $studentAvailable = false;
        else throw $e;
    }
    $report = ccAudit($meetings, $expected, $membership, $batches, $studentAvailable);
    if ($studentAvailable) {
        $studentPrograms = [];
        foreach ($membership as $row) $studentPrograms[(int)$row['program_id']] = true;
        foreach ($batches as $b) {
            if (!isset($studentPrograms[(int)$b['program_id']])) {
                $report['warnings'][] = 'No DEMO student membership for program ' . $b['program_code'] . '; student overlaps in that program were not fully verified.';
            }
        }
        if ($report['warnings']) {
            $report['passed'] = false;
            if ($report['total_issues'] === 0) $report['status'] = 'CHECK_INCOMPLETE';
        }
    }
    ccReply(200, [
        'success' => true,
        'status' => 'SAVED_SCHEDULE_AUDIT_READY',
        'scope' => 'ALL_ACTIVE_DEMO_PROGRAMS_IN_PERIOD',
        'period' => $period,
        'active_programs' => array_values(array_unique(array_column($batches, 'program_code'))),
        'active_batches' => array_map(static fn($b) => ['batch_id' => (int)$b['batch_id'], 'program_code' => $b['program_code'], 'meetings' => (int)$b['meeting_count']], $batches),
        'audit' => $report,
        'database_write' => false,
        'limitations' => ['Checks saved ACTIVE DEMO meeting records; does not regenerate a schedule, invoke the independent Python scheduling audit, or validate every scheduling policy (such as break placement and database time-slot eligibility).', 'Do not use this endpoint as a final authorization to save or replace a timetable.'],
    ]);
} catch (Throwable $e) {
    error_log('BCP Module 3 audit: ' . $e->getMessage());
    ccReply(500, ['success' => false, 'status' => 'CONFLICT_CHECK_ERROR', 'message' => 'Unable to audit saved schedules. Inspect the PHP error log and schema.', 'database_write' => false]);
}
