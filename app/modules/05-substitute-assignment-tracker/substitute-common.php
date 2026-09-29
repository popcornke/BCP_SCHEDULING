<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';
authRequire(true);
/** Module 5: local DEMO only. The faculty group owns faculty profiles/leave approvals. */
require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function subReply(int $code, array $out): never {
    http_response_code($code);
    echo json_encode($out + ['database_write' => false], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function subFail(int $http, string $status, string $message): never {
    throw new SubstituteError($message, $http, $status);
}
final class SubstituteError extends RuntimeException {
    public function __construct(string $message, public int $httpCode, public string $apiStatus) {
        parent::__construct($message);
    }
}
function subGuard(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        subFail(405, 'METHOD_NOT_ALLOWED', 'Use the required HTTP method.');
    }
}
function subPdo(): PDO {
    $pdo = getDatabase();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}
function subRows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function subOne(PDO $pdo, string $sql, array $params = []): ?array {
    $rows = subRows($pdo, $sql, $params);
    return $rows[0] ?? null;
}
function subInt(mixed $value, string $label): int {
    $x = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($x === false) subFail(400, 'INVALID_INPUT', 'Invalid ' . $label . '.');
    return (int)$x;
}
function subDate(mixed $value): string {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        subFail(400, 'INVALID_DATE', 'Choose a valid YYYY-MM-DD duty date.');
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value || (int)$d->format('N') === 7) {
        subFail(400, 'INVALID_DATE', 'Choose a valid Monday–Saturday class date.');
    }
    return $value;
}
function subClock(string $clock): int {
    $parts = explode(':', $clock);
    return (int)$parts[0] * 60 + (int)$parts[1];
}
function subOverlap(string $startA, string $endA, string $startB, string $endB): bool {
    return subClock($startA) < subClock($endB) && subClock($startB) < subClock($endA);
}
function subTextLength(string $text): int {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}
function subWeekday(string $date): string {
    return (new DateTimeImmutable($date))->format('l');
}
function subMeeting(PDO $pdo, int $id, int $period, bool $lock = false): array {
    $sql = "SELECT m.meeting_id,m.batch_id,m.section_subject_id,m.teacher_id AS original_teacher_id,
        m.room_id,m.delivery_mode,m.day_of_week,
        TIME_FORMAT(m.start_time,'%H:%i') AS start_time,
        TIME_FORMAT(m.end_time,'%H:%i') AS end_time,
        ss.subject_id,s.subject_code,s.subject_title,
        sec.section_id,sec.section_code,sec.program_id,sec.student_count,
        p.program_code,t.teacher_name AS original_teacher_name,b.academic_period_id
        FROM schedule_meetings m
        JOIN schedule_batches b ON b.batch_id=m.batch_id AND b.status='ACTIVE' AND b.data_origin='DEMO'
        JOIN section_subjects ss ON ss.section_subject_id=m.section_subject_id
        JOIN subjects s ON s.subject_id=ss.subject_id
        JOIN sections sec ON sec.section_id=ss.section_id
        JOIN programs p ON p.program_id=sec.program_id
        JOIN teachers t ON t.teacher_id=m.teacher_id
        WHERE m.meeting_id=:meeting AND b.academic_period_id=:period
        AND sec.academic_period_id=:section_period AND sec.is_active=1 AND sec.data_origin='DEMO'
        AND sec.program_id=b.program_id AND s.program_id=sec.program_id
        LIMIT 1";
    // Caller locks ACTIVE batch rows first; do not rely on a lock on a joined derived query.
    $row = subOne($pdo, $sql, ['meeting' => $id, 'period' => $period, 'section_period' => $period]);
    if (!$row) subFail(409, 'MEETING_NOT_ACTIVE', 'The selected class is no longer in an ACTIVE DEMO timetable. Refresh.');
    return $row;
}
function subAvailable(PDO $pdo, int $teacherId, int $period, string $day, string $start, string $end): bool {
    $rows = subRows($pdo, "SELECT TIME_FORMAT(start_time,'%H:%i') AS a,
        TIME_FORMAT(end_time,'%H:%i') AS b,availability_status
        FROM teacher_availability WHERE teacher_id=:teacher AND academic_period_id=:period
        AND day_of_week=:day", ['teacher'=>$teacherId,'period'=>$period,'day'=>$day]);
    $covered = false;
    foreach ($rows as $row) {
        if ($row['availability_status'] === 'UNAVAILABLE' && subOverlap($start,$end,$row['a'],$row['b'])) return false;
        if ($row['availability_status'] === 'AVAILABLE' && subClock($row['a']) <= subClock($start)
            && subClock($row['b']) >= subClock($end)) $covered = true;
    }
    // Fail closed on missing/partial availability rather than assuming availability.
    return $covered;
}
function subTeacherIssues(PDO $pdo, array $meeting, string $date, int $candidate): array {
    $issues = [];
    $period = (int)$meeting['academic_period_id'];
    $teacher = subOne($pdo,"SELECT teacher_id,teacher_name,program_id,status,data_origin,max_daily_hours,max_weekly_hours
        FROM teachers WHERE teacher_id=:id", ['id'=>$candidate]);
    if (!$teacher || $teacher['status'] !== 'ACTIVE' || $teacher['data_origin'] !== 'DEMO'
        || (int)$teacher['program_id'] !== (int)$meeting['program_id']) {
        return ['Professor is inactive, unavailable, or belongs to a different program.'];
    }
    if ($candidate === (int)$meeting['original_teacher_id']) $issues[] = 'Choose someone other than the original professor.';
    if (!subOne($pdo, "SELECT authorization_id FROM teacher_subject_authorizations
        WHERE teacher_id=:t AND subject_id=:s AND data_origin='DEMO' LIMIT 1",
        ['t'=>$candidate, 's'=>$meeting['subject_id']])) $issues[] = 'Professor is not authorized to teach this subject.';
    $day = subWeekday($date);
    $start = (string)$meeting['start_time']; $end = (string)$meeting['end_time'];
    if ($day !== $meeting['day_of_week']) $issues[] = 'Selected date does not match the scheduled class weekday.';
    if (!subAvailable($pdo,$candidate,$period,$day,$start,$end)) $issues[] = 'Professor has no approved availability for the complete class time.';
    // All ACTIVE class batches in period, including OTHER programs. Keep original
    // classes reserved as well: conservative unless faculty system confirms release.
    $classes = subRows($pdo, "SELECT m.meeting_id,m.day_of_week,
        TIME_FORMAT(m.start_time,'%H:%i') AS a,TIME_FORMAT(m.end_time,'%H:%i') AS b
        FROM schedule_meetings m JOIN schedule_batches sb ON sb.batch_id=m.batch_id
        WHERE sb.academic_period_id=:period AND sb.status='ACTIVE' AND m.teacher_id=:teacher
        AND m.day_of_week=:day", ['period'=>$period,'teacher'=>$candidate,'day'=>$day]);
    foreach ($classes as $class) {
        if (subOverlap($start,$end,$class['a'],$class['b'])) {
            $issues[] = 'Professor already has a regular class at this time (meeting #'.$class['meeting_id'].').';
            break;
        }
    }
    $otherDuties = subRows($pdo,"SELECT sa.substitute_assignment_id,sa.meeting_id,
        TIME_FORMAT(sm.start_time,'%H:%i') AS a,TIME_FORMAT(sm.end_time,'%H:%i') AS b
        FROM substitute_assignments sa JOIN schedule_meetings sm ON sm.meeting_id=sa.meeting_id
        JOIN schedule_batches sb ON sb.batch_id=sm.batch_id AND sb.status='ACTIVE'
        WHERE sa.academic_period_id=:period AND sa.duty_date=:date AND sa.status='ACTIVE'
        AND sa.substitute_teacher_id=:teacher",
        ['period'=>$period,'date'=>$date,'teacher'=>$candidate]);
    foreach ($otherDuties as $duty) {
        if (subOverlap($start,$end,$duty['a'],$duty['b'])) {
            $issues[] = 'Professor already has a substitute duty at this time (#'.$duty['substitute_assignment_id'].').';
            break;
        }
    }
    // Exam proctor assignments refer to actual calendar dates (not recurring days).
    $exams = subRows($pdo,"SELECT em.exam_meeting_id,
        TIME_FORMAT(em.start_time,'%H:%i') AS a,TIME_FORMAT(em.end_time,'%H:%i') AS b
        FROM exam_meetings em JOIN exam_batches eb ON eb.exam_batch_id=em.exam_batch_id
        WHERE eb.academic_period_id=:period AND eb.status='ACTIVE'
        AND em.exam_date=:date AND em.proctor_id=:teacher",
        ['period'=>$period,'date'=>$date,'teacher'=>$candidate]);
    foreach ($exams as $exam) {
        if (subOverlap($start,$end,$exam['a'],$exam['b'])) {
            $issues[] = 'Professor is assigned to proctor an exam at this time (#'.$exam['exam_meeting_id'].').';
            break;
        }
    }
    // Conservative teaching-load check: recurring timetable is counted even
    // if a separate dated substitution covers one of this teacher's classes.
    // Faculty/leave system owns the authoritative workload policy.
    $base = subRows($pdo, "SELECT m.day_of_week,
        TIME_TO_SEC(TIMEDIFF(m.end_time,m.start_time))/60 AS minutes
        FROM schedule_meetings m JOIN schedule_batches sb ON sb.batch_id=m.batch_id
        WHERE sb.academic_period_id=:period AND sb.status='ACTIVE' AND m.teacher_id=:teacher",
        ['period'=>$period,'teacher'=>$candidate]);
    $dailyMinutes = 0; $weeklyMinutes = 0;
    foreach ($base as $class) {
        $minutes = (float)$class['minutes'];
        $weeklyMinutes += $minutes;
        if ($class['day_of_week'] === $day) $dailyMinutes += $minutes;
    }
    $weekStart = (new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');
    $weekEnd = (new DateTimeImmutable($date))->modify('sunday this week')->format('Y-m-d');
    $duties = subRows($pdo, "SELECT sa.duty_date,
        TIME_TO_SEC(TIMEDIFF(m.end_time,m.start_time))/60 AS minutes
        FROM substitute_assignments sa JOIN schedule_meetings m ON m.meeting_id=sa.meeting_id
        JOIN schedule_batches sb ON sb.batch_id=m.batch_id AND sb.status='ACTIVE'
        WHERE sa.academic_period_id=:period AND sa.status='ACTIVE'
        AND sa.substitute_teacher_id=:teacher AND sa.duty_date BETWEEN :week_start AND :week_end",
        ['period'=>$period,'teacher'=>$candidate,'week_start'=>$weekStart,'week_end'=>$weekEnd]);
    foreach ($duties as $duty) {
        $weeklyMinutes += (float)$duty['minutes'];
        if ($duty['duty_date'] === $date) $dailyMinutes += (float)$duty['minutes'];
    }
    $plannedMinutes = subClock($end)-subClock($start);
    if ($dailyMinutes + $plannedMinutes > (int)$teacher['max_daily_hours']*60)
        $issues[] = 'Assigning this class would exceed the professor\'s configured daily teaching hours.';
    if ($weeklyMinutes + $plannedMinutes > (int)$teacher['max_weekly_hours']*60)
        $issues[] = 'Assigning this class would exceed the professor\'s configured weekly teaching hours.';
    return $issues;
}
function subSession(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('BCP_SUBSTITUTE_DEMO');
        session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict',
            'path'=>'/BCP_SCHEDULING/app/modules/05-substitute-assignment-tracker']);
        session_start();
    }
    if (empty($_SESSION['sub_csrf'])) $_SESSION['sub_csrf'] = bin2hex(random_bytes(32));
}
