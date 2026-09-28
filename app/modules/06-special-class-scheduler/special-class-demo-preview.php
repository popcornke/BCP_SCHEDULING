<?php
declare(strict_types=1);
/**
 * Module 6 Phase 6F isolated DEMO-only weekly preview: no school-approval claims.
 * Requires verified institutional evidence stored by an authorized DB operator;
 * the API CANNOT approve policies, set durations, alter sections or save classes.
 * Retain Phase 6B special-class-preparation.php unchanged.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/special-class-calendar.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

final class Sc6dException extends RuntimeException {
    public function __construct(public int $httpStatus, public string $errorStatus, string $message) { parent::__construct($message); }
}
function sc6dFail(int $http, string $status, string $message): never { throw new Sc6dException($http,$status,$message); }
function sc6dReply(int $http, array $data): never {
    http_response_code($http);
    echo json_encode($data + ['database_write'=>false,'saving_enabled'=>false], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}
function sc6dRows(PDO $db, string $sql, array $params=[]): array {
    $stmt=$db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function sc6dId(mixed $id, string $label): int {
    $value=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($value===false) sc6dFail(400,'INVALID_SELECTION','Choose a valid '.$label.'.');
    return (int)$value;
}
function sc6dDate(mixed $raw): DateTimeImmutable {
    if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$raw)) sc6dFail(422,'INVALID_DATE','Use ISO calendar dates.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new DateTimeZone('Asia/Manila'));
    if (!$date || $date->format('Y-m-d')!==$raw) sc6dFail(422,'INVALID_DATE','Invalid calendar date.');
    return $date;
}
function sc6dDates(array $body): array {
    $start=sc6dDate($body['start_date']??null); $end=sc6dDate($body['end_date']??null);
    if ($end<$start || $start->diff($end)->days>111) sc6dFail(422,'INVALID_WEEKLY_RANGE','A DEMO request must span at most 16 weeks.');
    $selected=$body['weekdays']??null;
    $allowed=['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    if (!is_array($selected) || !$selected || count($selected)>6 || count(array_unique($selected))!==count($selected)) sc6dFail(422,'INVALID_WEEKDAYS','Choose distinct Monday-Saturday weekdays.');
    foreach ($selected as $weekday) if (!is_string($weekday) || !in_array($weekday,$allowed,true)) sc6dFail(422,'INVALID_WEEKDAYS','Invalid weekday.');
    $result=[];
    for($date=$start; $date<=$end; $date=$date->modify('+1 day')) {
        if(in_array($date->format('l'),$selected,true)) $result[]=['date'=>$date->format('Y-m-d'),'day_of_week'=>$date->format('l')];
    }
    if (!$result) sc6dFail(422,'NO_WEEKLY_OCCURRENCES','No requested weekly dates in the range.');
    if ($result[0]['date'] < (new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->format('Y-m-d')) sc6dFail(422,'PAST_DATE','Use future or current dates for new scheduling previews.');
    return $result;
}
function sc6dContext(PDO $db, int $period, int $program): array {
    $p=sc6dRows($db,"SELECT academic_period_id,academic_year,semester,period_status FROM academic_periods WHERE academic_period_id=:p AND period_status='DEMO'",['p'=>$period]);
    $g=sc6dRows($db,'SELECT program_id,program_code,program_name FROM programs WHERE program_id=:g AND is_active=1',['g'=>$program]);
    if (count($p)!==1 || count($g)!==1) sc6dFail(404,'DEMO_CONTEXT_NOT_FOUND','No matching active program and DEMO period.');
    return ['period'=>$p[0],'program'=>$g[0]];
}
function sc6dPolicy(PDO $db, int $period, int $program, string $type): ?array {
    $rows=sc6dRows($db,'SELECT special_class_policy_id,academic_period_id,program_id,class_type,recurrence,approved_duration_minutes,delivery_mode,policy_status,approval_reference,approved_by,approval_evidence_sha256,approved_at,octoberian_specific_rules_approved FROM special_class_policies WHERE academic_period_id=:p AND program_id=:g AND class_type=:t LIMIT 1', ['p'=>$period,'g'=>$program,'t'=>$type]);
    return $rows[0]??null;
}
function sc6dPolicyReady(?array $p, string $type): bool {
    if (!$p || $p['policy_status']!=='APPROVED' || $p['recurrence']!=='WEEKLY') return false;
    $m=(int)$p['approved_duration_minutes'];
    if ($m<30 || $m>900 || $m%30!==0 || !in_array($p['delivery_mode'],['F2F','ONLINE'],true)) return false;
    if (!is_string($p['approval_reference']) || trim($p['approval_reference'])==='' || !is_string($p['approved_by']) || trim($p['approved_by'])==='' || empty($p['approved_at'])) return false;
    if (!is_string($p['approval_evidence_sha256']) || !preg_match('/^[a-f0-9]{64}$/iD',$p['approval_evidence_sha256'])) return false;
    return $type!=='OCTOBERIAN' || (int)$p['octoberian_specific_rules_approved']===1;
}
function sc6dSafePolicy(?array $p, string $type): array {
    return ['policy_status'=>$p['policy_status']??'NOT_RECORDED',
        'recurrence'=>'WEEKLY','class_type'=>$type,
        'duration_recorded'=>isset($p['approved_duration_minutes']),
        'delivery_mode_recorded'=>isset($p['delivery_mode']),
        'octoberian_rules_approved'=>(bool)($p['octoberian_specific_rules_approved']??false),
        'approval_record_complete'=>sc6dPolicyReady($p,$type)];
}
function sc6dParticipants(PDO $db, int $period, int $program, mixed $selected): array {
    if (!is_array($selected) || count($selected)<1 || count($selected)>50) sc6dFail(422,'INVALID_PARTICIPANTS','Select between 1 and 50 actual students.');
    $ids=[];
    foreach ($selected as $raw) { $id=sc6dId($raw,'student'); if (isset($ids[$id])) sc6dFail(422,'DUPLICATE_STUDENT','A student was selected more than once.'); $ids[$id]=true; }
    $in=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT st.student_id,st.student_number,st.home_section_id,st.major_section_id FROM students st
        JOIN sections home ON home.section_id=st.home_section_id
        LEFT JOIN sections major ON major.section_id=st.major_section_id
        WHERE st.academic_period_id=? AND st.program_id=? AND st.data_origin='DEMO'
        AND home.academic_period_id=st.academic_period_id AND home.program_id=st.program_id
        AND home.data_origin='DEMO' AND home.is_active=1
        AND (st.major_section_id IS NULL OR (major.academic_period_id=st.academic_period_id
        AND major.program_id=st.program_id AND major.data_origin='DEMO' AND major.is_active=1))
        AND st.student_id IN ($in) ORDER BY st.student_id";
    $rows=sc6dRows($db,$sql,array_merge([$period,$program],array_keys($ids)));
    if (count($rows)!==count($ids)) sc6dFail(409,'STUDENT_MEMBERSHIP_CHANGED','Refresh selected students; some no longer have valid current memberships.');
    return $rows;
}
function sc6dSnapshot(PDO $db, array $body, array $context, array $policy, array $dates): array {
    $period=(int)$context['period']['academic_period_id']; $program=(int)$context['program']['program_id'];
    $subject=sc6dId($body['subject_id']??null,'subject');
    $teacher=sc6dId($body['teacher_id']??null,'professor');
    $eligible=sc6dRows($db,"SELECT t.teacher_id,t.max_daily_hours,t.max_weekly_hours
       FROM teachers t JOIN teacher_subject_authorizations a ON a.teacher_id=t.teacher_id
       JOIN subjects s ON s.subject_id=a.subject_id
       WHERE t.teacher_id=:teacher AND t.program_id=:program AND t.status='ACTIVE' AND t.data_origin='DEMO'
       AND a.subject_id=:subject AND a.data_origin='DEMO'
       AND s.subject_id=:subject2 AND s.program_id=:program2 AND s.semester=:semester AND s.is_active=1",
       ['teacher'=>$teacher,'program'=>$program,'subject'=>$subject,'subject2'=>$subject,'program2'=>$program,'semester'=>$context['period']['semester']]);
    if (count($eligible)!==1) sc6dFail(422,'UNAUTHORIZED_FACULTY_OR_SUBJECT','Selected professor is not authorized for this current subject and program.');
    $participants=sc6dParticipants($db,$period,$program,$body['student_ids']??null);
    $batches=sc6dRows($db,"SELECT batch_id FROM schedule_batches WHERE academic_period_id=:p AND program_id=:g AND status='ACTIVE' AND data_origin='DEMO'",['p'=>$period,'g'=>$program]);
    if (count($batches)!==1) sc6dFail(409,'ACTIVE_CLASS_BATCH_REQUIRED','Exactly one current program class batch is required.');
    $requestedType=$body['class_type'];
    // All ACTIVE PROGRAMS must be loaded; never limit room/teacher conflicts to BSIT.
    $classes=sc6dRows($db,"SELECT m.meeting_id,ss.section_id,m.teacher_id,m.room_id,m.day_of_week,TIME_FORMAT(m.start_time,'%H:%i') AS start_time,TIME_FORMAT(m.end_time,'%H:%i') AS end_time
        FROM schedule_meetings m JOIN schedule_batches b ON b.batch_id=m.batch_id
        JOIN section_subjects ss ON ss.section_subject_id=m.section_subject_id
        WHERE b.academic_period_id=:p AND b.status='ACTIVE' ORDER BY m.meeting_id",['p'=>$period]);
    $exams=sc6dRows($db,"SELECT em.exam_date,ss.section_id,em.proctor_id,em.room_id,TIME_FORMAT(em.start_time,'%H:%i') AS start_time,TIME_FORMAT(em.end_time,'%H:%i') AS end_time
        FROM exam_meetings em JOIN exam_batches eb ON eb.exam_batch_id=em.exam_batch_id
        JOIN section_subjects ss ON ss.section_subject_id=em.section_subject_id
        WHERE eb.academic_period_id=:p AND eb.status='ACTIVE' ORDER BY em.exam_meeting_id",['p'=>$period]);
    $substitutes=sc6dRows($db,"SELECT sa.duty_date,sa.substitute_teacher_id,TIME_FORMAT(m.start_time,'%H:%i') AS start_time,TIME_FORMAT(m.end_time,'%H:%i') AS end_time
        FROM substitute_assignments sa JOIN schedule_meetings m ON m.meeting_id=sa.meeting_id
        JOIN schedule_batches b ON b.batch_id=sa.class_batch_id AND b.status='ACTIVE'
        WHERE sa.academic_period_id=:p AND sa.status='ACTIVE' ORDER BY sa.substitute_assignment_id",['p'=>$period]);
    $special=sc6dRows($db,"SELECT m.special_meeting_id,m.special_class_id,m.meeting_date,m.teacher_id,m.room_id,TIME_FORMAT(m.start_time,'%H:%i') AS start_time,TIME_FORMAT(m.end_time,'%H:%i') AS end_time
        FROM special_class_meetings m JOIN special_classes c ON c.special_class_id=m.special_class_id
        WHERE c.academic_period_id=:p AND c.status='ACTIVE' AND m.status='SCHEDULED' ORDER BY m.special_meeting_id",['p'=>$period]);
    foreach ($special as &$meeting) {
        $roster=sc6dRows($db,"SELECT scs.student_number,st.student_id FROM special_class_students scs
            JOIN special_classes c ON c.special_class_id=scs.special_class_id
            LEFT JOIN students st ON st.student_number=scs.student_number
                AND st.academic_period_id=c.academic_period_id AND st.program_id=c.program_id
            WHERE scs.special_class_id=:class", ['class'=>(int)$meeting['special_class_id']]);
        if (!$roster || in_array(null,array_column($roster,'student_id'),true)) {
            sc6dFail(409,'INCOMPLETE_SPECIAL_CLASS_ROSTER','An existing special class has missing student memberships. Resolve before generating another timetable.');
        }
        $meeting['participant_student_ids']=array_map(static fn($r)=>(int)$r['student_id'],$roster);
        unset($meeting['special_meeting_id'],$meeting['special_class_id']);
    } unset($meeting);
    $rooms=sc6dRows($db,"SELECT room_id,program_id,capacity,status FROM rooms WHERE data_origin='DEMO' AND status='AVAILABLE' AND (program_id=:g OR program_id IS NULL) ORDER BY room_id",['g'=>$program]);
    $timeSlots=sc6dRows($db,"SELECT day_of_week,TIME_FORMAT(start_time,'%H:%i') AS start_time,TIME_FORMAT(end_time,'%H:%i') AS end_time,is_active FROM time_slots WHERE is_active=1 AND data_origin='DEMO' ORDER BY day_of_week,start_time",[]);
    $roomAvailability=sc6dRows($db,"SELECT ra.room_id,ra.day_of_week,TIME_FORMAT(ra.start_time,'%H:%i') AS start_time,TIME_FORMAT(ra.end_time,'%H:%i') AS end_time,ra.availability_status
        FROM room_availability ra WHERE ra.academic_period_id=:p AND ra.data_origin='DEMO'",['p'=>$period]);
    $teacherAvailability=sc6dRows($db,"SELECT ta.teacher_id,ta.day_of_week,TIME_FORMAT(ta.start_time,'%H:%i') AS start_time,TIME_FORMAT(ta.end_time,'%H:%i') AS end_time,ta.availability_status
        FROM teacher_availability ta WHERE ta.academic_period_id=:p AND ta.data_origin='DEMO'",['p'=>$period]);
    return [
      'policy'=>['test_duration_minutes'=>(int)$policy['test_duration_minutes'],
          'demo_test_mode'=>true,'policy_source'=>'ISOLATED_DEMO_SIMULATION','duration_verified_from_database'=>false,
          'octoberian_rules_approved'=>false],
      'request'=>['class_type'=>$requestedType,'recurrence'=>'WEEKLY','program_id'=>$program,'teacher_id'=>$teacher,
          'teacher_authorized'=>true,'delivery_mode'=>$policy['delivery_mode'],
          'max_daily_hours'=>(int)$eligible[0]['max_daily_hours'],'max_weekly_hours'=>(int)$eligible[0]['max_weekly_hours'],
          'participants'=>$participants],
      'weekly_occurrences'=>$dates,'existing_classes'=>$classes,'existing_exams'=>$exams,
      'existing_substitutions'=>$substitutes,'existing_special_classes'=>$special,
      'time_slots'=>$timeSlots,'rooms'=>$rooms,'room_availability'=>$roomAvailability,'teacher_availability'=>$teacherAvailability,
    ];
}
// SIMULATED test parameters (not academic dates or school-approved duration).
// Localhost only; this file is never included by the official Phase 6E endpoint.
const SC6F_DEMO_START = '2026-09-25';
const SC6F_DEMO_END   = '2026-12-31';
const SC6F_TEST_MINUTES = 60;
const SC6F_TEST_MODE = 'F2F';
try {
    if (!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'], true)) {
        sc6dFail(403,'LOCAL_DEMO_ONLY','DEMO scheduling is accessible from localhost only.');
    }
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
        sc6dFail(405,'POST_REQUIRED','Open special-class-demo-test.php to run the read-only DEMO.');
    }
    $body=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($body) || ($body['demo_acknowledged']??false)!==true) {
        sc6dFail(400,'DEMO_ACK_REQUIRED','Confirm that 60-minute F2F and sample calendar are demonstration values, not school approval.');
    }
    // Hard server-side scope; period/program/class/room facts still come from fresh MySQL queries.
    if (($body['period_id']??null)!==1 || ($body['program_id']??null)!==4
        || !in_array($body['class_type']??null,['REMEDIAL','IRREGULAR'],true)) {
        sc6dFail(422,'DEMO_SCOPE_ONLY','Only BSIT period #1 Remedial/Irregular requests are covered by this isolated DEMO.');
    }
    $dates=sc6dDates($body);
    if (!is_array($body['weekdays']??null) || count($body['weekdays'])!==1) {
        sc6dFail(422,'SINGLE_WEEKDAY_DEMO_ONLY','Choose exactly one weekly weekday for this simple demonstration.');
    }
    if ($body['start_date'] < SC6F_DEMO_START || $body['end_date'] > SC6F_DEMO_END) {
        sc6dFail(422,'OUTSIDE_SIMULATED_CALENDAR','For this DEMO test only, choose dates inside '.SC6F_DEMO_START.' to '.SC6F_DEMO_END.'. These are not official school dates.');
    }
    $db=getDatabase();
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $context=sc6dContext($db,1,4);
    if ($context['program']['program_code']!=='BSIT') {
        sc6dFail(409,'PROGRAM_CHANGED','Program #4 no longer belongs to BSIT.');
    }
    $testPolicy=['test_duration_minutes'=>SC6F_TEST_MINUTES,'delivery_mode'=>SC6F_TEST_MODE];
    $snapshot=sc6dSnapshot($db,$body,$context,$testPolicy,$dates);
    // Validate the server-built DEMO policy contract. No official approval fields are sent.
    if (($snapshot['policy']['duration_verified_from_database']??null)!==false
        || ($snapshot['policy']['policy_source']??null)!=='ISOLATED_DEMO_SIMULATION'
        || isset($snapshot['policy']['approval_reference'])) {
        sc6dFail(500,'UNSAFE_DEMO_POLICY','DEMO snapshot unexpectedly asserts institutional approval.');
    }
    if (!function_exists('curl_init')) {
        sc6dFail(503,'CURL_UNAVAILABLE','PHP cURL is required. Enable curl in XAMPP php.ini.');
    }
    $curl=curl_init('http://127.0.0.1:8001/demo/weekly-preview');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>35,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($snapshot,JSON_THROW_ON_ERROR)]);
    $raw=curl_exec($curl); $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $err=curl_error($curl); curl_close($curl);
    if (!is_string($raw) || $http!==200) {
        error_log('BCP 6F DEMO Python error: HTTP '.$http.' '.$err);
        sc6dFail(502,'DEMO_SOLVER_UNAVAILABLE','Start the isolated Python DEMO server on 127.0.0.1:8001. Details: '.$err);
    }
    $result=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($result)) sc6dFail(502,'INVALID_DEMO_RESULT','DEMO Python service returned invalid JSON.');
    if (($result['success']??false)!==true) {
        sc6dReply(422,$result+['demo_only'=>true,'official_policy_approved'=>false]);
    }
    if (($result['status']??'')!=='SPECIAL_CLASS_DEMO_PREVIEW_READY'
        || ($result['independent_audit']['passed']??false)!==true
        || !is_array($result['assignments']??null) || count($result['assignments'])!==count($dates)) {
        sc6dFail(502,'DEMO_AUDIT_FAILED','The DEMO result is incomplete or failed its independent audit.');
    }
    sc6dReply(200,['success'=>true,'status'=>'SPECIAL_CLASS_DEMO_PREVIEW_READY',
        'phase'=>'ISOLATED_DEMO_UNSAVED_PREVIEW','demo_only'=>true,'official_policy_approved'=>false,
        'sample_calendar'=>['start'=>SC6F_DEMO_START,'end'=>SC6F_DEMO_END,'official'=>false],
        'test_duration_minutes'=>SC6F_TEST_MINUTES,'test_delivery_mode'=>SC6F_TEST_MODE,
        'period'=>$context['period'],'program'=>$context['program'],
        'occurrence_count'=>count($dates),'participant_count'=>count($snapshot['request']['participants']),
        'assignments'=>$result['assignments'],'independent_audit'=>$result['independent_audit'],
        'notice'=>'LOCAL DEMO SIMULATION ONLY. Dates and duration are test values, not BCP approvals. No records were saved.']);
} catch (Sc6dException $e) {
    sc6dReply($e->httpStatus,['success'=>false,'status'=>$e->errorStatus,'message'=>$e->getMessage(),'demo_only'=>true]);
} catch (JsonException $e) {
    sc6dReply(400,['success'=>false,'status'=>'INVALID_JSON','message'=>'Invalid JSON request.','demo_only'=>true]);
} catch (Throwable $e) {
    error_log('BCP Module 6 Phase 6F DEMO: '.$e->getMessage());
    sc6dReply(500,['success'=>false,'status'=>'DEMO_PREVIEW_ERROR','message'=>'Unable to build DEMO preview. Check Apache PHP error log.','demo_only'=>true]);
}
