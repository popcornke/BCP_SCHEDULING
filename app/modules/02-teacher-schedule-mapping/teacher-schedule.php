<?php

declare(strict_types=1);



/**
 * BCP MODULE 2 — TEACHER SCHEDULE MAPPING (DEMO, READ ONLY)
 * Reads existing faculty authorization, availability and ACTIVE timetable.
 * No faculty, curriculum, room, timetable, or student records are modified.
 * Local-only DEMO endpoint. Integrate app auth/roles before OFFICIAL use.
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire(true, ['ADMIN', 'SCHEDULER']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function teacherReply(int $http, array $data): never
{
    http_response_code($http);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function teacherMinutes(string $clock): int
{
    $parts = explode(':', $clock);
    return (int)$parts[0] * 60 + (int)$parts[1];
}
function teacherHours(int $minutes): float
{
    return round($minutes / 60, 2);
}
function teacherIssue(string $type, string $detail, array $extra = []): array
{
    return array_merge(['type' => $type, 'message' => $detail], $extra);
}

$programCode = strtoupper(trim((string)($_GET['program'] ?? 'BSIT')));
$periodId = filter_var($_GET['period_id'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$teacherId = isset($_GET['teacher_id'])
    ? filter_var($_GET['teacher_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;
if (!preg_match('/^[A-Z0-9]{2,30}$/', $programCode) || $periodId === false || $teacherId === false) {
    teacherReply(400, ['success' => false, 'status' => 'INVALID_PARAMETERS', 'message' => 'Choose a valid program, period, and teacher.']);
}
try {
    $pdo = getDatabase();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $st = $pdo->prepare("SELECT program_id, program_code, program_name FROM programs WHERE program_code=:code AND is_active=1 AND education_level='College' LIMIT 1");
    $st->execute(['code' => $programCode]);
    $program = $st->fetch(PDO::FETCH_ASSOC);
    if (!$program) teacherReply(404, ['success' => false, 'status' => 'PROGRAM_NOT_FOUND', 'message' => 'Active college program not found.']);

    $st = $pdo->prepare("SELECT academic_period_id, academic_year, semester, period_status FROM academic_periods WHERE academic_period_id=:period AND period_status='DEMO' LIMIT 1");
    $st->execute(['period' => $periodId]);
    $period = $st->fetch(PDO::FETCH_ASSOC);
    if (!$period) teacherReply(404, ['success' => false, 'status' => 'DEMO_PERIOD_NOT_FOUND', 'message' => 'DEMO academic period not found.']);

    $st = $pdo->prepare("SELECT batch_id FROM schedule_batches WHERE program_id=:program AND academic_period_id=:period AND data_origin='DEMO' AND status='ACTIVE' ORDER BY batch_id");
    $st->execute(['program' => $program['program_id'], 'period' => $periodId]);
    $batches = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (count($batches) > 1) {
        teacherReply(409, ['success' => false, 'status' => 'MULTIPLE_ACTIVE_BATCHES', 'message' => 'More than one ACTIVE DEMO timetable exists for this program and period. Review the database first.']);
    }
    $batchId = $batches[0] ?? null;

    // Faculty list: do not invent professors or borrow faculty from other programs.
    $st = $pdo->prepare("SELECT teacher_id, employee_no, teacher_name, program_id, max_daily_hours, max_weekly_hours
        FROM teachers WHERE program_id=:program AND data_origin='DEMO' AND status='ACTIVE' ORDER BY teacher_name, teacher_id");
    $st->execute(['program' => $program['program_id']]);
    $faculty = $st->fetchAll(PDO::FETCH_ASSOC);
    $counts = [];
    if ($batchId !== null) {
        $st = $pdo->prepare('SELECT teacher_id, COUNT(*) AS meetings, COUNT(DISTINCT section_subject_id) AS subject_sections,
            SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time))/60) AS teaching_minutes
            FROM schedule_meetings WHERE batch_id=:batch GROUP BY teacher_id');
        $st->execute(['batch' => $batchId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $counts[(int)$c['teacher_id']] = $c;
    }
    foreach ($faculty as &$f) {
        $id = (int)$f['teacher_id'];
        $f['teacher_id'] = $id;
        $f['program_id'] = (int)$f['program_id'];
        $f['max_daily_hours'] = (int)$f['max_daily_hours'];
        $f['max_weekly_hours'] = (int)$f['max_weekly_hours'];
        $f['saved_meetings'] = (int)($counts[$id]['meetings'] ?? 0);
        $f['assigned_section_subjects'] = (int)($counts[$id]['subject_sections'] ?? 0);
        $f['weekly_teaching_hours'] = teacherHours((int)($counts[$id]['teaching_minutes'] ?? 0));
    }
    unset($f);

    if ($teacherId === null) {
        teacherReply(200, [
            'success' => true,
            'status' => 'TEACHER_LIST_READY',
            'data_origin' => 'DEMO',
            'program' => $program,
            'period' => $period,
            'has_saved_schedule' => $batchId !== null,
            'batch_id' => $batchId,
            'total_teachers' => count($faculty),
            'teachers' => $faculty,
            'database_write' => false,
        ]);
    }
    $teacher = null;
    foreach ($faculty as $f) {
        if ($f['teacher_id'] === $teacherId) {
            $teacher = $f;
            break;
        }
    }
    if ($teacher === null) {
        teacherReply(404, ['success' => false, 'status' => 'TEACHER_NOT_FOUND', 'message' => 'This active DEMO professor does not belong to the selected program.']);
    }

    // Authorization is the permitted-subject list, not a new assignment.
    $st = $pdo->prepare("SELECT s.subject_id, s.subject_code, s.subject_title, s.year_level, s.semester
        FROM teacher_subject_authorizations a
        JOIN subjects s ON s.subject_id=a.subject_id
        WHERE a.teacher_id=:teacher AND a.data_origin='DEMO'
          AND s.program_id=:program AND s.semester=:semester AND s.is_active=1
        ORDER BY s.year_level, s.subject_code");
    $st->execute(['teacher' => $teacherId, 'program' => $program['program_id'], 'semester' => $period['semester']]);
    $authorized = $st->fetchAll(PDO::FETCH_ASSOC);
    $authorizedIds = [];
    foreach ($authorized as &$a) {
        $a['subject_id'] = (int)$a['subject_id'];
        $a['semester'] = (int)$a['semester'];
        $authorizedIds[$a['subject_id']] = true;
    }
    unset($a);

    $st = $pdo->prepare("SELECT day_of_week, TIME_FORMAT(start_time,'%H:%i') AS start_time,
        TIME_FORMAT(end_time,'%H:%i') AS end_time, availability_status
        FROM teacher_availability
        WHERE teacher_id=:teacher AND academic_period_id=:period AND data_origin='DEMO'
        ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),start_time");
    $st->execute(['teacher' => $teacherId, 'period' => $periodId]);
    $availability = $st->fetchAll(PDO::FETCH_ASSOC);

    $meetings = [];
    if ($batchId !== null) {
        $st = $pdo->prepare(<<<'SQL'
SELECT m.meeting_id, m.section_subject_id, m.teacher_id, m.room_id,
       ss.subject_id, sec.section_id, sec.section_code, sec.section_type,
       sec.program_id AS section_program_id, sec.academic_period_id AS section_period_id,
       s.program_id AS subject_program_id, s.subject_code, s.subject_title,
       r.room_name, m.delivery_mode, m.day_of_week,
       TIME_FORMAT(m.start_time,'%H:%i') AS start_time,
       TIME_FORMAT(m.end_time,'%H:%i') AS end_time
FROM schedule_meetings m
JOIN section_subjects ss ON ss.section_subject_id=m.section_subject_id
JOIN sections sec ON sec.section_id=ss.section_id
JOIN subjects s ON s.subject_id=ss.subject_id
LEFT JOIN rooms r ON r.room_id=m.room_id
WHERE m.batch_id=:batch AND m.teacher_id=:teacher
ORDER BY FIELD(m.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),
         m.start_time, m.meeting_id
SQL);
        $st->execute(['batch' => $batchId, 'teacher' => $teacherId]);
        $meetings = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $dailyMinutes = array_fill_keys($days, 0);
    $issues = [];
    $ownMeetings = [];
    $subjectSections = [];
    $sections = [];
    $modeMinutes = ['F2F' => 0, 'ONLINE' => 0];
    foreach ($meetings as &$m) {
        $m['meeting_id'] = (int)$m['meeting_id'];
        $m['section_subject_id'] = (int)$m['section_subject_id'];
        $m['teacher_id'] = (int)$m['teacher_id'];
        $m['subject_id'] = (int)$m['subject_id'];
        $m['section_id'] = (int)$m['section_id'];
        $m['room_id'] = $m['room_id'] === null ? null : (int)$m['room_id'];
        $start = teacherMinutes($m['start_time']);
        $end = teacherMinutes($m['end_time']);
        $minutes = $end - $start;
        $m['duration_minutes'] = $minutes;
        $section = $m['section_code'];
        if ($minutes <= 0 || $start < 360 || $end > 1260) {
            $issues[] = teacherIssue('INVALID_CLASS_TIME', 'Saved meeting has an invalid time.', ['meeting_id' => $m['meeting_id']]);
        }
        if (
            (int)$m['section_program_id'] !== (int)$program['program_id']
            || (int)$m['subject_program_id'] !== (int)$program['program_id']
            || (int)$m['section_period_id'] !== $periodId
        ) {
            $issues[] = teacherIssue('PROGRAM_OR_PERIOD_MISMATCH', 'Saved meeting points to another program or academic period.', ['meeting_id' => $m['meeting_id']]);
        }
        if (!isset($authorizedIds[$m['subject_id']])) {
            $issues[] = teacherIssue('UNAUTHORIZED_SUBJECT', 'Professor is not authorized for this saved subject in the selected semester.', ['meeting_id' => $m['meeting_id'], 'subject_code' => $m['subject_code']]);
        }
        if (($m['delivery_mode'] === 'ONLINE' && $m['room_id'] !== null)
            || ($m['delivery_mode'] === 'F2F' && $m['room_id'] === null)
        ) {
            $issues[] = teacherIssue('INVALID_ROOM_ASSIGNMENT', 'Saved room does not match delivery mode.', ['meeting_id' => $m['meeting_id']]);
        }
        $day = (string)$m['day_of_week'];
        if (!array_key_exists($day, $dailyMinutes)) {
            $issues[] = teacherIssue('INVALID_DAY', 'Unexpected saved meeting day.', ['meeting_id' => $m['meeting_id']]);
        } else {
            $dailyMinutes[$day] += max(0, $minutes);
        }
        if (array_key_exists($m['delivery_mode'], $modeMinutes)) {
            $modeMinutes[$m['delivery_mode']] += max(0, $minutes);
        }
        $subjectSections[$m['section_subject_id']] = true;
        $sections[$m['section_id']] = true;
        $available = false;
        foreach ($availability as $window) {
            if ($window['day_of_week'] !== $day) continue;
            $wStart = teacherMinutes($window['start_time']);
            $wEnd = teacherMinutes($window['end_time']);
            if ($window['availability_status'] === 'UNAVAILABLE' && $start < $wEnd && $wStart < $end) {
                $available = false;
                break;
            }
            if ($window['availability_status'] === 'AVAILABLE' && $wStart <= $start && $end <= $wEnd) $available = true;
        }
        if (!$available) {
            $issues[] = teacherIssue('OUTSIDE_AVAILABILITY', 'Saved meeting is outside professor availability or crosses unavailable time.', ['meeting_id' => $m['meeting_id']]);
        }
        $ownMeetings[] = $m;
        unset($m['section_program_id'], $m['subject_program_id'], $m['section_period_id']);
    }
    unset($m);

    // Safety: check this same permanent teacher_id against ALL active DEMO batches
    // for this academic period, even if an inconsistent record belongs to another program.
    $allActive = [];
    $st = $pdo->prepare(<<<'SQL'
SELECT m.meeting_id, m.teacher_id, b.program_id AS batch_program_id,
       m.day_of_week, TIME_FORMAT(m.start_time,'%H:%i') AS start_time,
       TIME_FORMAT(m.end_time,'%H:%i') AS end_time
FROM schedule_meetings m
JOIN schedule_batches b ON b.batch_id=m.batch_id
WHERE m.teacher_id=:teacher AND b.academic_period_id=:period
  AND b.status='ACTIVE' AND b.data_origin='DEMO'
ORDER BY FIELD(m.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), m.start_time, m.meeting_id
SQL);
    $st->execute(['teacher' => $teacherId, 'period' => $periodId]);
    $allActive = $st->fetchAll(PDO::FETCH_ASSOC);
    $overlaps = [];
    for ($i = 0, $n = count($allActive); $i < $n; $i++) {
        $a = $allActive[$i];
        if ((int)$a['batch_program_id'] !== (int)$program['program_id']) {
            $issues[] = teacherIssue('CROSS_PROGRAM_FACULTY', 'Saved meeting uses this professor in a different program.', ['meeting_id' => (int)$a['meeting_id']]);
        }
        for ($j = $i + 1; $j < $n; $j++) {
            $b = $allActive[$j];
            if ($a['day_of_week'] !== $b['day_of_week']) break;
            if ($a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time']) {
                $overlaps[] = [
                    'day_of_week' => $a['day_of_week'],
                    'first_meeting_id' => (int)$a['meeting_id'],
                    'second_meeting_id' => (int)$b['meeting_id']
                ];
            }
        }
    }
    if ($overlaps) $issues[] = teacherIssue('TEACHER_TIME_OVERLAP', 'Professor has overlapping ACTIVE class meetings.', ['overlap_count' => count($overlaps)]);

    $weeklyMinutes = array_sum($dailyMinutes);
    if ($weeklyMinutes > $teacher['max_weekly_hours'] * 60) {
        $issues[] = teacherIssue('WEEKLY_LOAD_EXCEEDED', 'Saved weekly teaching load exceeds the faculty maximum.');
    }
    $dailyLoad = [];
    foreach ($dailyMinutes as $day => $minutes) {
        if ($minutes > $teacher['max_daily_hours'] * 60) {
            $issues[] = teacherIssue('DAILY_LOAD_EXCEEDED', 'Saved teaching load exceeds the daily maximum on ' . $day . '.', ['day_of_week' => $day]);
        }
        $dailyLoad[] = ['day_of_week' => $day, 'teaching_minutes' => $minutes, 'teaching_hours' => teacherHours($minutes)];
    }
    // Check both delivery modes remain with one professor for each section-subject.
    if ($batchId !== null && $subjectSections) {
        $st = $pdo->prepare('SELECT m.section_subject_id, m.teacher_id, m.delivery_mode
            FROM schedule_meetings m WHERE m.batch_id=:batch');
        $st->execute(['batch' => $batchId]);
        $bySubject = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int)$row['section_subject_id'];
            if (isset($subjectSections[$sid])) $bySubject[$sid][] = $row;
        }
        foreach ($subjectSections as $sid => $_) {
            $rows = $bySubject[$sid] ?? [];
            $teacherIds = array_unique(array_map(static fn($r) => (int)$r['teacher_id'], $rows));
            $modes = array_column($rows, 'delivery_mode');
            if (
                count($teacherIds) !== 1 || count($rows) !== 2 || count(array_unique($modes)) !== 2
                || !in_array('F2F', $modes, true) || !in_array('ONLINE', $modes, true)
            ) {
                $issues[] = teacherIssue('SECTION_SUBJECT_MAPPING', 'F2F/Online mapping is incomplete or professors differ for one section-subject.', ['section_subject_id' => $sid]);
            }
        }
    }

    teacherReply(200, [
        'success' => true,
        'status' => $batchId === null ? 'TEACHER_SCHEDULE_EMPTY' : 'TEACHER_SCHEDULE_LOADED',
        'data_origin' => 'DEMO',
        'program' => $program,
        'period' => $period,
        'batch_id' => $batchId,
        'teacher' => $teacher,
        'authorized_subjects' => $authorized,
        'availability' => $availability,
        'meeting_count' => count($meetings),
        'meetings' => $meetings,
        'summary' => [
            'assigned_subject_sections' => count($subjectSections),
            'sections' => count($sections),
            'f2f_hours' => teacherHours($modeMinutes['F2F']),
            'online_hours' => teacherHours($modeMinutes['ONLINE']),
            'weekly_teaching_hours' => teacherHours($weeklyMinutes),
            'max_weekly_hours' => $teacher['max_weekly_hours'],
            'max_daily_hours' => $teacher['max_daily_hours']
        ],
        'daily_load' => $dailyLoad,
        'teacher_overlap_check' => ['passed' => count($overlaps) === 0, 'total_conflicts' => count($overlaps), 'conflicts' => $overlaps],
        'mapping_validation' => ['passed' => count($issues) === 0, 'total_issues' => count($issues), 'issues' => $issues],
        'independent_school_wide_audit_rerun' => false,
        'database_write' => false,
    ]);
} catch (Throwable $e) {
    error_log('Teacher Schedule Mapping DEMO: ' . $e->getMessage());
    teacherReply(500, [
        'success' => false,
        'status' => 'TEACHER_SCHEDULE_ERROR',
        'message' => 'Unable to load faculty mapping. Check the database schema and PHP error log.'
    ]);
}
