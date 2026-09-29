<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';
authRequire(true);
/** Module 10 / Phase 10A: LOCAL DEMO read-only saved calendar. No booking or scheduler calls. */
require_once __DIR__ . '/../../config/database.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

final class CalendarRequestError extends RuntimeException {
    public function __construct(public int $httpCode, public string $apiCode, string $message) {
        parent::__construct($message);
    }
}
function ciFail(int $http, string $code, string $message): never {
    throw new CalendarRequestError($http, $code, $message);
}
function ciReply(int $http, array $data): never {
    http_response_code($http);
    echo json_encode($data + [
        'read_only' => true, 'database_write' => false, 'saving_enabled' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}
function ciRows(PDO $db, string $query, array $bindings = []): array {
    $statement = $db->prepare($query);
    $statement->execute($bindings);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function ciId(mixed $value, string $field, bool $allowZero = false): int {
    if ((!is_string($value) && !is_int($value)) ||
        !preg_match('/^\d{1,10}$/D', (string)$value)) {
        ciFail(400, 'INVALID_FILTER', 'Invalid ' . $field . '.');
    }
    $number = (int)$value;
    if ($number < ($allowZero ? 0 : 1) || $number > 2147483647) {
        ciFail(400, 'INVALID_FILTER', 'Invalid ' . $field . '.');
    }
    return $number;
}
function ciDate(mixed $value): DateTimeImmutable {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        ciFail(400, 'INVALID_DATE', 'Use YYYY-MM-DD.');
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        ciFail(400, 'INVALID_DATE', 'Choose an actual calendar date.');
    }
    return $dt;
}
function ciTableExists(PDO $db, string $table): bool {
    // Constant, trusted table names only; no table identifiers from browser input.
    if (!in_array($table, ['substitute_assignments', 'special_classes', 'special_class_meetings'], true)) {
        return false;
    }
    return count(ciRows($db, 'SHOW TABLES LIKE ' . $db->quote($table))) !== 0;
}
function ciTeacher(PDO $db): array {
    return ciRows($db, "SELECT teacher_id,teacher_name,program_id FROM teachers
        WHERE status='ACTIVE' AND data_origin='DEMO' ORDER BY teacher_name,teacher_id");
}
function ciCatalog(PDO $db): array {
    $periods = ciRows($db, "SELECT academic_period_id,academic_year,semester,period_status
        FROM academic_periods WHERE period_status='DEMO' ORDER BY academic_period_id DESC");
    $programs = ciRows($db, "SELECT program_id,program_code,program_name FROM programs
        WHERE is_active=1 AND education_level='College' ORDER BY program_code");
    return ['periods'=>$periods,'programs'=>$programs,'teachers'=>ciTeacher($db)];
}
function ciEvent(array $props): array {
    return $props + ['teacher_id'=>null, 'teacher_name'=>null, 'room_id'=>null,
        'room_name'=>null, 'section_code'=>null, 'subject_code'=>null, 'subject_title'=>null,
        'program_code'=>null, 'delivery_mode'=>null, 'is_substitute'=>false,
        'original_teacher_name'=>null, 'note'=>null];
}
/** Saved meetings have weekday recurrence, NOT proof that a class actually took place on each date. */
function ciBuildEvents(PDO $db, int $periodId, int $programId, int $teacherId,
    DateTimeImmutable $start, DateTimeImmutable $end): array {
    $from = $start->format('Y-m-d');
    $to = $end->format('Y-m-d');
    $warnings = [];
    $classes = ciRows($db, "SELECT m.meeting_id,m.batch_id,m.teacher_id,t.teacher_name,
            m.room_id,r.room_name,m.delivery_mode,m.day_of_week,
            TIME_FORMAT(m.start_time,'%H:%i') AS start_time,
            TIME_FORMAT(m.end_time,'%H:%i') AS end_time,
            sec.section_code,sec.section_type,s.subject_code,s.subject_title,p.program_code
        FROM schedule_meetings m
        JOIN schedule_batches b ON b.batch_id=m.batch_id
        JOIN programs p ON p.program_id=b.program_id
        JOIN section_subjects ss ON ss.section_subject_id=m.section_subject_id
        JOIN sections sec ON sec.section_id=ss.section_id
        JOIN subjects s ON s.subject_id=ss.subject_id
        JOIN teachers t ON t.teacher_id=m.teacher_id
        LEFT JOIN rooms r ON r.room_id=m.room_id
        WHERE b.academic_period_id=:period AND b.status='ACTIVE' AND b.data_origin='DEMO'
            AND (:all_programs=0 OR b.program_id=:program)
        ORDER BY m.day_of_week,m.start_time,m.meeting_id",
        ['period'=>$periodId,'all_programs'=>$programId===0?0:1,'program'=>$programId]);

    $byWeekday = [];
    $classIds = [];
    foreach ($classes as $row) {
        $byWeekday[$row['day_of_week']][] = $row;
        $classIds[(int)$row['meeting_id']] = true;
    }
    $subByDateMeeting = [];
    if (ciTableExists($db, 'substitute_assignments')) {
        $substitutions = ciRows($db, "SELECT a.substitute_assignment_id,a.meeting_id,
                DATE_FORMAT(a.duty_date,'%Y-%m-%d') AS duty_date,
                a.substitute_teacher_id,t.teacher_name AS substitute_teacher_name
            FROM substitute_assignments a
            JOIN schedule_batches b ON b.batch_id=a.class_batch_id
            JOIN teachers t ON t.teacher_id=a.substitute_teacher_id
            WHERE a.academic_period_id=:period AND b.academic_period_id=:batch_period
                AND b.status='ACTIVE' AND b.data_origin='DEMO' AND a.status='ACTIVE'
                AND a.duty_date BETWEEN :date_from AND :date_to
            ORDER BY a.substitute_assignment_id",
            ['period'=>$periodId,'batch_period'=>$periodId,'date_from'=>$from,'date_to'=>$to]);
        foreach ($substitutions as $row) {
            if (!isset($classIds[(int)$row['meeting_id']])) continue;
            $key=$row['duty_date'].'|'.$row['meeting_id'];
            if (isset($subByDateMeeting[$key])) {
                $warnings[]='Multiple ACTIVE substitute records exist for one saved meeting/date.';
                continue;
            }
            $subByDateMeeting[$key]=$row;
        }
    } else {
        $warnings[]='Substitute assignments table is not installed; substitute duties are not shown.';
    }

    $events=[];
    for ($date=$start; $date<=$end; $date=$date->modify('+1 day')) {
        $dateText=$date->format('Y-m-d');
        foreach ($byWeekday[$date->format('l')]??[] as $m) {
            $sub=$subByDateMeeting[$dateText.'|'.$m['meeting_id']]??null;
            $effectiveId=$sub ? (int)$sub['substitute_teacher_id'] : (int)$m['teacher_id'];
            if ($teacherId!==0 && $teacherId!==$effectiveId) continue;
            $events[]=ciEvent([
                'event_id'=>'CLASS-'.$m['meeting_id'].'-'.$dateText,
                'type'=>$sub?'SUBSTITUTE_CLASS':'REGULAR_CLASS',
                'reference_id'=>(int)$m['meeting_id'], 'batch_id'=>(int)$m['batch_id'],
                'date'=>$dateText,'start_time'=>$m['start_time'],'end_time'=>$m['end_time'],
                'teacher_id'=>$effectiveId,
                'teacher_name'=>$sub?$sub['substitute_teacher_name']:$m['teacher_name'],
                'original_teacher_name'=>$sub?$m['teacher_name']:null,
                'is_substitute'=>$sub!==null,
                'substitute_assignment_id'=>$sub?(int)$sub['substitute_assignment_id']:null,
                'room_id'=>$m['room_id']===null?null:(int)$m['room_id'],
                'room_name'=>$m['room_name'],'delivery_mode'=>$m['delivery_mode'],
                'section_code'=>$m['section_code'],'section_type'=>$m['section_type'],
                'subject_code'=>$m['subject_code'],'subject_title'=>$m['subject_title'],
                'program_code'=>$m['program_code'],
                'note'=>$sub?'Substitute covers this occurrence only.':'Weekly saved pattern; date-specific cancellations are not verified.'
            ]);
        }
    }

    $exams=ciRows($db, "SELECT e.exam_meeting_id,e.exam_batch_id,e.proctor_id,t.teacher_name AS proctor_name,
            e.room_id,r.room_name,DATE_FORMAT(e.exam_date,'%Y-%m-%d') AS exam_date,
            TIME_FORMAT(e.start_time,'%H:%i') AS start_time,
            TIME_FORMAT(e.end_time,'%H:%i') AS end_time,
            sec.section_code,sec.section_type,s.subject_code,s.subject_title,p.program_code
        FROM exam_meetings e
        JOIN exam_batches b ON b.exam_batch_id=e.exam_batch_id
        JOIN programs p ON p.program_id=b.program_id
        JOIN section_subjects ss ON ss.section_subject_id=e.section_subject_id
        JOIN sections sec ON sec.section_id=ss.section_id
        JOIN subjects s ON s.subject_id=ss.subject_id
        JOIN teachers t ON t.teacher_id=e.proctor_id
        LEFT JOIN rooms r ON r.room_id=e.room_id
        WHERE b.academic_period_id=:period AND b.status='ACTIVE' AND b.data_origin='DEMO'
            AND e.exam_date BETWEEN :date_from AND :date_to
            AND (:all_programs=0 OR b.program_id=:program)
        ORDER BY e.exam_date,e.start_time,e.exam_meeting_id",
        ['period'=>$periodId,'date_from'=>$from,'date_to'=>$to,
            'all_programs'=>$programId===0?0:1,'program'=>$programId]);
    foreach ($exams as $e) {
        if ($teacherId!==0 && $teacherId!==(int)$e['proctor_id']) continue;
        $events[]=ciEvent([
            'event_id'=>'EXAM-'.$e['exam_meeting_id'],'type'=>'EXAM',
            'reference_id'=>(int)$e['exam_meeting_id'],'batch_id'=>(int)$e['exam_batch_id'],
            'date'=>$e['exam_date'],'start_time'=>$e['start_time'],'end_time'=>$e['end_time'],
            'teacher_id'=>(int)$e['proctor_id'],'teacher_name'=>$e['proctor_name'],
            'room_id'=>$e['room_id']===null?null:(int)$e['room_id'],
            'room_name'=>$e['room_name'],'delivery_mode'=>'F2F',
            'section_code'=>$e['section_code'],'section_type'=>$e['section_type'],
            'subject_code'=>$e['subject_code'],'subject_title'=>$e['subject_title'],
            'program_code'=>$e['program_code'],
            'note'=>'Saved dated examination; regular weekly classes may also appear on this date.'
        ]);
    }

    if (ciTableExists($db,'special_classes') && ciTableExists($db,'special_class_meetings')) {
        $specials=ciRows($db, "SELECT sm.special_meeting_id,sm.special_class_id,sc.class_type,
                sm.teacher_id,t.teacher_name,sm.room_id,r.room_name,sm.delivery_mode,
                DATE_FORMAT(sm.meeting_date,'%Y-%m-%d') AS meeting_date,
                TIME_FORMAT(sm.start_time,'%H:%i') AS start_time,
                TIME_FORMAT(sm.end_time,'%H:%i') AS end_time,
                s.subject_code,s.subject_title,p.program_code
            FROM special_class_meetings sm
            JOIN special_classes sc ON sc.special_class_id=sm.special_class_id
            JOIN programs p ON p.program_id=sc.program_id
            JOIN subjects s ON s.subject_id=sc.subject_id
            JOIN teachers t ON t.teacher_id=sm.teacher_id
            LEFT JOIN rooms r ON r.room_id=sm.room_id
            WHERE sc.academic_period_id=:period AND sc.status='ACTIVE'
                AND sm.status='SCHEDULED' AND sc.data_origin='DEMO'
                AND sm.meeting_date BETWEEN :date_from AND :date_to
                AND (:all_programs=0 OR sc.program_id=:program)
            ORDER BY sm.meeting_date,sm.start_time,sm.special_meeting_id",
            ['period'=>$periodId,'date_from'=>$from,'date_to'=>$to,
                'all_programs'=>$programId===0?0:1,'program'=>$programId]);
        foreach ($specials as $s) {
            if ($teacherId!==0 && $teacherId!==(int)$s['teacher_id']) continue;
            $events[]=ciEvent([
                'event_id'=>'SPECIAL-'.$s['special_meeting_id'],'type'=>'SPECIAL_CLASS',
                'reference_id'=>(int)$s['special_meeting_id'],'batch_id'=>null,
                'date'=>$s['meeting_date'],'start_time'=>$s['start_time'],'end_time'=>$s['end_time'],
                'teacher_id'=>(int)$s['teacher_id'],'teacher_name'=>$s['teacher_name'],
                'room_id'=>$s['room_id']===null?null:(int)$s['room_id'],
                'room_name'=>$s['room_name'],'delivery_mode'=>$s['delivery_mode'],
                'subject_code'=>$s['subject_code'],'subject_title'=>$s['subject_title'],
                'program_code'=>$s['program_code'],'class_type'=>$s['class_type'],
                'note'=>'Only saved ACTIVE special classes appear. Unsaved DEMO previews are excluded.'
            ]);
        }
    } else {
        $warnings[]='Special class tables are unavailable; special-class meetings are not shown.';
    }
    usort($events, static fn($a,$b) => [$a['date'],$a['start_time'],$a['end_time'],$a['type'],$a['event_id']]
        <=> [$b['date'],$b['start_time'],$b['end_time'],$b['type'],$b['event_id']]);
    $counts=['REGULAR_CLASS'=>0,'SUBSTITUTE_CLASS'=>0,'EXAM'=>0,'SPECIAL_CLASS'=>0];
    foreach ($events as $event) $counts[$event['type']]++;
    return ['events'=>$events, 'counts'=>$counts, 'warnings'=>array_values(array_unique($warnings))];
}

try {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {
        ciFail(405,'METHOD_NOT_ALLOWED','GET only.');
    }
    $action=(string)($_GET['action']??'catalog');
    if (!in_array($action,['catalog','events'],true)) {
        ciFail(400,'INVALID_ACTION','Use action=catalog or action=events.');
    }
    $db=getDatabase();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $catalog=ciCatalog($db);
    if ($action==='catalog') {
        ciReply(200,['success'=>true,'status'=>'CALENDAR_CATALOG_READY']+$catalog);
    }
    $periodId=ciId($_GET['period_id']??null,'academic period');
    $programId=ciId($_GET['program_id']??'0','program',true);
    $teacherId=ciId($_GET['teacher_id']??'0','teacher',true);
    $period=null;
    foreach ($catalog['periods'] as $row) {
        if ((int)$row['academic_period_id']===$periodId) {$period=$row;break;}
    }
    if ($period===null) ciFail(404,'PERIOD_NOT_FOUND','Selected DEMO period does not exist.');
    if ($programId!==0 && !array_filter($catalog['programs'],static fn($p)=>(int)$p['program_id']===$programId)) {
        ciFail(400,'PROGRAM_NOT_FOUND','Choose a valid college program.');
    }
    if ($teacherId!==0 && !array_filter($catalog['teachers'],static fn($t)=>(int)$t['teacher_id']===$teacherId)) {
        ciFail(400,'TEACHER_NOT_FOUND','Choose an active DEMO professor.');
    }
    $start=ciDate($_GET['start_date']??null);
    $end=ciDate($_GET['end_date']??null);
    $days=(int)$start->diff($end)->format('%r%a');
    if ($days<0 || $days>41) {
        ciFail(422,'INVALID_DATE_RANGE','Choose a forward date range of at most 42 calendar days.');
    }
    $result=ciBuildEvents($db,$periodId,$programId,$teacherId,$start,$end);
    ciReply(200,['success'=>true,'status'=>'CALENDAR_EVENTS_READY',
        'period'=>$period,'range'=>['start_date'=>$start->format('Y-m-d'),'end_date'=>$end->format('Y-m-d')],
        'filters'=>['program_id'=>$programId,'teacher_id'=>$teacherId],
        'counts'=>$result['counts'],'event_count'=>count($result['events']),
        'events'=>$result['events'],'warnings'=>$result['warnings'],
        'coverage_note'=>'Recurring classes are expanded from ACTIVE saved weekday patterns. Exam and substitute entries use actual stored dates. Overlaps are displayed, not automatically resolved. Academic calendar, holidays, class cancellations and real attendance are not verified.']);
} catch (CalendarRequestError $e) {
    ciReply($e->httpCode,['success'=>false,'status'=>$e->apiCode,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('BCP Module 10 calendar: '.$e->getMessage());
    ciReply(500,['success'=>false,'status'=>'CALENDAR_API_ERROR',
        'message'=>'Unable to load the saved calendar. Check the Apache/PHP error log; no data was changed.']);
}
