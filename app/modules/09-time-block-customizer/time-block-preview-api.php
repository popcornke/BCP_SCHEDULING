<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';
authRequire(true);
/** Module 9 Phase 9B. Purely read-only DB-wide time-slot impact analysis; NO SAVE ROUTE. */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/time-block-preview-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function tb9bReply(int $http,array $body): never {
    http_response_code($http);
    echo json_encode($body+['read_only'=>true,'database_write'=>false,'editing_enabled'=>false,
        'apply_enabled'=>false,'schedule_regeneration_performed'=>false],
        JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}
function tb9bRows(PDO $db,string $sql,array $params=[]): array {
    $statement=$db->prepare($sql); $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
        tb9bFail(405,'METHOD_NOT_ALLOWED','Use POST with JSON to preview; database saving is unavailable.');
    }
    $body=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
    if (!is_array($body)) tb9bFail(400,'INVALID_REQUEST','Expected a JSON object.');
    $day=$body['day']??null; $start=$body['start_time']??null;
    $end=$body['end_time']??null; $action=$body['action']??null;
    if (!is_string($day) || !is_string($start) || !is_string($end) || !is_string($action)) {
        tb9bFail(400,'INVALID_REQUEST','Choose weekday, start, end and preview action.');
    }
    if (!in_array($day,['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],true)) {
        tb9bFail(422,'INVALID_DAY','Choose a valid scheduling weekday.');
    }
    $weekdayNumber = array_search($day, ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'], true) + 1;
    $db=getDatabase(); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    // No academic_period_id in time_slots: changed slots would apply to ALL academic periods using them.
    $records=tb9bRows($db,"SELECT time_slot_id,day_of_week,day_pattern,
        TIME_FORMAT(start_time,'%H:%i') AS start_time,
        TIME_FORMAT(end_time,'%H:%i') AS end_time,is_active
        FROM time_slots WHERE day_of_week=:day ORDER BY start_time,time_slot_id",['day'=>$day]);
    $plan=tb9bPlan($records,$day,$start,$end,$action);
    $changed=$plan['changed_slots']; $impact=[];
    if ($action==='DISABLE' && $changed) {
        // Recurring class meetings in EVERY active academic period, not just the selected program.
        $classes=tb9bRows($db,"SELECT 'REGULAR_CLASS' AS booking_type,m.meeting_id AS booking_id,
            b.batch_id,b.academic_period_id,p.program_code,s.section_code,sub.subject_code,
            m.day_of_week AS weekday,NULL AS meeting_date,
            TIME_FORMAT(m.start_time,'%H:%i') AS start_time,
            TIME_FORMAT(m.end_time,'%H:%i') AS end_time
            FROM schedule_meetings m JOIN schedule_batches b ON b.batch_id=m.batch_id
            JOIN programs p ON p.program_id=b.program_id
            JOIN section_subjects ss ON ss.section_subject_id=m.section_subject_id
            JOIN sections s ON s.section_id=ss.section_id
            JOIN subjects sub ON sub.subject_id=ss.subject_id
            WHERE b.status='ACTIVE' AND m.day_of_week=:day ORDER BY b.batch_id,m.meeting_id",['day'=>$day]);
        $exams=tb9bRows($db,"SELECT 'EXAM' AS booking_type,e.exam_meeting_id AS booking_id,
            eb.exam_batch_id AS batch_id,eb.academic_period_id,p.program_code,s.section_code,sub.subject_code,
            DAYNAME(e.exam_date) AS weekday,DATE_FORMAT(e.exam_date,'%Y-%m-%d') AS meeting_date,
            TIME_FORMAT(e.start_time,'%H:%i') AS start_time,
            TIME_FORMAT(e.end_time,'%H:%i') AS end_time
            FROM exam_meetings e JOIN exam_batches eb ON eb.exam_batch_id=e.exam_batch_id
            JOIN programs p ON p.program_id=eb.program_id
            JOIN section_subjects ss ON ss.section_subject_id=e.section_subject_id
            JOIN sections s ON s.section_id=ss.section_id
            JOIN subjects sub ON sub.subject_id=ss.subject_id
            WHERE eb.status='ACTIVE' AND DAYOFWEEK(e.exam_date)=:weekday ORDER BY e.exam_date,e.exam_meeting_id",['weekday'=>$weekdayNumber]);
        $impact=tb9bImpact(array_merge($classes,$exams),$changed);
        // Read existing special-class meetings if the separate Module 6 storage foundation exists.
        $exists=tb9bRows($db,"SHOW TABLES LIKE 'special_class_meetings'");
        if ($exists) {
            $special=tb9bRows($db,"SELECT 'SPECIAL_CLASS' AS booking_type,sm.special_meeting_id AS booking_id,
                sc.special_class_id AS batch_id,sc.academic_period_id,p.program_code,
                'INDIVIDUAL_PARTICIPANTS' AS section_code,sub.subject_code,
                DAYNAME(sm.meeting_date) AS weekday,DATE_FORMAT(sm.meeting_date,'%Y-%m-%d') AS meeting_date,
                TIME_FORMAT(sm.start_time,'%H:%i') AS start_time,
                TIME_FORMAT(sm.end_time,'%H:%i') AS end_time
                FROM special_class_meetings sm JOIN special_classes sc ON sc.special_class_id=sm.special_class_id
                JOIN programs p ON p.program_id=sc.program_id
                JOIN subjects sub ON sub.subject_id=sc.subject_id
                WHERE sc.status='ACTIVE' AND sm.status='SCHEDULED' AND DAYOFWEEK(sm.meeting_date)=:weekday
                ORDER BY sm.meeting_date,sm.special_meeting_id",['weekday'=>$weekdayNumber]);
            $impact=array_merge($impact,tb9bImpact($special,$changed));
        }
    }
    $before=['slot_count'=>count($plan['source_slots']),
        'active_slots'=>count(array_filter($plan['source_slots'],static fn($s)=>$s['is_active'])),
        'one_hour_windows'=>tb9bWindows($plan['source_slots'],60),
        'two_hour_windows'=>tb9bWindows($plan['source_slots'],120)];
    $after=['slot_count'=>count($plan['proposed_slots']),
        'active_slots'=>count(array_filter($plan['proposed_slots'],static fn($s)=>$s['is_active'])),
        'one_hour_windows'=>tb9bWindows($plan['proposed_slots'],60),
        'two_hour_windows'=>tb9bWindows($plan['proposed_slots'],120)];
    // Do not overstate readiness: this preview is NOT a school-policy approval or authorization to edit.
    tb9bReply(200,['success'=>true,'status'=>'TIME_BLOCK_CHANGE_PREVIEW_READY',
        'scope'=>'ALL_PERIODS_GLOBAL_TIME_SLOTS',
        'requested_change'=>['day'=>$day,'day_pattern'=>$plan['day_pattern'],
            'start_time'=>$start,'end_time'=>$end,'action'=>$action],
        'selected_slot_ids'=>$plan['selected_slot_ids'],
        'changed_slot_ids'=>array_map(static fn($s)=>$s['time_slot_id'],$changed),
        'changed_slot_count'=>count($changed),
        'source_slots'=>$plan['source_slots'],'proposed_slots'=>$plan['proposed_slots'],
        'before'=>$before,'after'=>$after,
        'affected_saved_meetings'=>$impact,'affected_saved_meeting_count'=>count($impact),
        'blocked_by_active_schedules'=>$action==='DISABLE' && count($impact)>0,
        'no_effect'=>!$changed,
        'unverified_requirements'=>[
            'School policy and scheduling-hour approval',
            'Other timetable policies, candidate feasibility, and complete independent school-wide audit',
            'Time-slot versioning by academic period and safe atomic regeneration/replacement',
            'Date-specific class suspensions/holidays and unsaved previews are not audited here',
        ],
        'notice'=>'Simulation only. Global time_slots are shared across academic periods. The 60/120-minute windows are illustrative combinations of existing slots, not changes to approved subject durations. No database records or saved timetables were modified.']);
} catch(Tb9bPreviewException $e) {
    tb9bReply($e->httpStatus,['success'=>false,'status'=>$e->statusCode,'message'=>$e->getMessage()]);
} catch(JsonException $e) {
    tb9bReply(400,['success'=>false,'status'=>'INVALID_JSON','message'=>'Malformed JSON request.']);
} catch(Throwable $e) {
    error_log('BCP Module 9 Phase 9B: '.$e->getMessage());
    tb9bReply(500,['success'=>false,'status'=>'TIME_BLOCK_PREVIEW_ERROR',
        'message'=>'Unable to calculate time-block impact. Check the Apache/PHP error log; no changes were made.']);
}
