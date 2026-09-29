<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';
authRequire(true);
/** BCP Module 7: DEMO, read-only, as-recorded room availability on a selected date/time. */
require_once __DIR__ . '/../../config/database.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

final class RoomCheckException extends RuntimeException {
    public function __construct(public int $http, public string $statusCode, string $message) { parent::__construct($message); }
}
function rcFail(int $http, string $status, string $message): never { throw new RoomCheckException($http,$status,$message); }
function rcReply(int $http, array $body): never {
    http_response_code($http);
    echo json_encode($body + ['database_write'=>false,'saving_enabled'=>false], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    exit;
}
function rcRows(PDO $db, string $sql, array $params=[]): array {
    $query=$db->prepare($sql); $query->execute($params); return $query->fetchAll(PDO::FETCH_ASSOC);
}
function rcMinutes(string $raw): int {
    $clock=substr($raw,0,5);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$clock)) rcFail(422,'INVALID_TIME','Use HH:MM (24-hour clock).');
    return (int)substr($clock,0,2)*60+(int)substr($clock,3,2);
}
function rcOverlaps(int $a, int $b, int $c, int $d): bool { return $a<$d && $c<$b; }
/** Merge adjacent and overlapping allowed time windows: an entire interval must be covered. */
function rcCovered(int $start, int $end, array $windows): bool {
    usort($windows, static fn($a,$b) => $a[0]<=>$b[0]);
    $through=$start;
    foreach ($windows as [$a,$b]) {
        if ($a>$through) break;
        if ($b>$through) $through=$b;
        if ($through >= $end) return true;
    }
    return false;
}
function rcClock(int $mins): string { return sprintf('%02d:%02d',intdiv($mins,60),$mins%60); }
function rcId(mixed $value, string $field, bool $allowZero=false): int {
    if (!is_scalar($value) || !preg_match('/^\d{1,10}$/D',(string)$value)) rcFail(400,'INVALID_INPUT','Invalid '.$field.'.');
    $n=(int)$value;
    if ($n<($allowZero?0:1)) rcFail(400,'INVALID_INPUT','Invalid '.$field.'.');
    return $n;
}
function rcDate(string $raw): DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$raw)) rcFail(422,'INVALID_DATE','Use YYYY-MM-DD.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new DateTimeZone('Asia/Manila'));
    if (!$date || $date->format('Y-m-d')!==$raw) rcFail(422,'INVALID_DATE','Select an actual calendar date.');
    return $date;
}
function rcAllowedDays(string $date): string { return rcDate($date)->format('l'); }
function rcAssess(array $rooms, array $availability, array $slots, array $classes, array $exams, array $specials, int $start, int $end, int $programId, int $capacity, string $roomType): array {
    $byRoom=[];
    foreach ($availability as $a) $byRoom[(int)$a['room_id']][]=$a;
    $slotWindows=[];
    foreach ($slots as $s) $slotWindows[]=[rcMinutes((string)$s['start_time']),rcMinutes((string)$s['end_time'])];
    $timeEligible=rcCovered($start,$end,$slotWindows);
    $events=[];
    foreach ([['REGULAR_CLASS',$classes],['EXAM',$exams],['SPECIAL_CLASS',$specials]] as [$kind,$items]) {
        foreach ($items as $event) {
            $id=(int)$event['room_id'];
            if ($id<1 || !rcOverlaps($start,$end,rcMinutes((string)$event['start_time']),rcMinutes((string)$event['end_time']))) continue;
            $events[$id][]=['type'=>$kind,'reference_id'=>(int)$event['reference_id'],
                'program_code'=>(string)($event['program_code']??''),
                'start_time'=>substr((string)$event['start_time'],0,5),'end_time'=>substr((string)$event['end_time'],0,5)];
        }
    }
    $result=[];$summary=['total'=>0,'available'=>0,'occupied'=>0,'unavailable'=>0,'unconfigured'=>0,'not_eligible'=>0];
    foreach ($rooms as $room) {
        $id=(int)$room['room_id'];$issues=[];$windows=[];$hasRule=false;
        $status=(string)$room['status'];
        if ($status!=='AVAILABLE') $issues[]=['type'=>'ROOM_STATUS','message'=>$status==='MAINTENANCE'?'Room is under maintenance.':'Room is marked unavailable.'];
        if (!$timeEligible) $issues[]=['type'=>'OUTSIDE_TIME_SLOTS','message'=>'Requested interval is not fully covered by active database time slots.'];
        foreach ($byRoom[$id]??[] as $w) {
            $hasRule=true;
            $a=rcMinutes((string)$w['start_time']);$b=rcMinutes((string)$w['end_time']);
            if (($w['availability_status']??'')==='AVAILABLE') $windows[]=[$a,$b];
            elseif (rcOverlaps($start,$end,$a,$b)) $issues[]=['type'=>'ROOM_UNAVAILABLE_WINDOW','message'=>'Blocked by room availability configuration '.rcClock($a).'–'.rcClock($b).'.'];
        }
        if (!$hasRule) $issues[]=['type'=>'NO_AVAILABILITY_RECORD','message'=>'No availability record for this room, weekday and period.'];
        elseif (!rcCovered($start,$end,$windows)) $issues[]=['type'=>'OUTSIDE_ROOM_AVAILABILITY','message'=>'Requested interval is not fully covered by AVAILABLE room windows.'];
        $bookings=$events[$id]??[];
        if ($bookings) $issues[]=['type'=>'ROOM_OCCUPIED','message'=>'An ACTIVE saved meeting overlaps this interval.'];
        $roomProgram=$room['program_id']===null?null:(int)$room['program_id'];
        $eligible=($programId===0 || $roomProgram===null || $roomProgram===$programId)
            && (int)$room['capacity'] >= $capacity && ($roomType==='' || $room['room_type']===$roomType);
        $why=[];
        if ($programId!==0 && $roomProgram!==null && $roomProgram!==$programId) $why[]='Room belongs to another program.';
        if ((int)$room['capacity']<$capacity) $why[]='Capacity below requested '.$capacity.'.';
        if ($roomType!=='' && $room['room_type']!==$roomType) $why[]='Room type does not match.';
        $state=(!$timeEligible || !$hasRule || !rcCovered($start,$end,$windows))?'UNCONFIGURED':($status!=='AVAILABLE' || count(array_filter($issues,static fn($i)=>$i['type']==='ROOM_UNAVAILABLE_WINDOW'))>0?'UNAVAILABLE':($bookings?'OCCUPIED':'AVAILABLE'));
        if (!$eligible) $state='NOT_ELIGIBLE';
        $summary['total']++;
        $summary[strtolower($state)]++;
        $result[]=['room_id'=>$id,'room_name'=>$room['room_name'],'building'=>$room['building'],
            'program_id'=>$roomProgram,'program_code'=>$room['program_code']??null,
            'room_type'=>$room['room_type'],'capacity'=>(int)$room['capacity'],
            'room_status'=>$status,'availability'=>$state,'eligible_for_request'=>$eligible,
            'bookings'=>$bookings,'issues'=>$issues,'eligibility_notes'=>$why];
    }
    usort($result, static fn($a,$b)=>strcmp($a['room_name'],$b['room_name']));
    return ['summary'=>$summary,'rooms'=>$result,'time_slot_covered'=>$timeEligible];
}
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='GET') rcFail(405,'METHOD_NOT_ALLOWED','GET only.');
    $action=(string)($_GET['action']??'catalog');
    if (!in_array($action,['catalog','check'],true)) rcFail(400,'INVALID_ACTION','Use action=catalog or action=check.');
    $db=getDatabase();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $periods=rcRows($db,"SELECT academic_period_id,academic_year,semester,period_status FROM academic_periods WHERE period_status='DEMO' ORDER BY academic_period_id DESC");
    $programs=rcRows($db,"SELECT program_id,program_code,program_name FROM programs WHERE is_active=1 AND education_level='College' ORDER BY program_code");
    if ($action==='catalog') rcReply(200,['success'=>true,'status'=>'ROOM_CATALOG_READY','periods'=>$periods,'programs'=>$programs,
        'room_types'=>['GENERAL','LABORATORY','SPECIALIZED'],'read_only'=>true]);
    $periodId=rcId($_GET['period_id']??'1','academic period');
    $programId=rcId($_GET['program_id']??'0','program',true);
    $capacity=rcId($_GET['capacity']??'0','required capacity',true);
    if ($capacity>10000) rcFail(422,'INVALID_CAPACITY','Requested capacity is too high.');
    $type=(string)($_GET['room_type']??'');
    if (!in_array($type,['','GENERAL','LABORATORY','SPECIALIZED'],true)) rcFail(422,'INVALID_ROOM_TYPE','Choose a valid room type.');
    $period=null;foreach ($periods as $p) if ((int)$p['academic_period_id']===$periodId) $period=$p;
    if (!$period) rcFail(404,'PERIOD_NOT_FOUND','Selected DEMO period was not found.');
    if ($programId!==0 && !array_filter($programs,static fn($p)=>(int)$p['program_id']===$programId)) rcFail(422,'PROGRAM_NOT_FOUND','Selected college program was not found.');
    $dateStr=(string)($_GET['date']??'');$day=rcAllowedDays($dateStr);
    if ($day==='Sunday') rcFail(422,'DAY_NOT_CONFIGURED','Sunday is not a configured school day in this DEMO database.');
    $start=rcMinutes((string)($_GET['start_time']??''));$end=rcMinutes((string)($_GET['end_time']??''));
    if ($start<360 || $end>1260 || $end<=$start) rcFail(422,'INVALID_INTERVAL','Select a valid interval within 06:00–21:00.');
    $params=['period'=>$periodId,'day'=>$day];
    $rooms=rcRows($db,"SELECT r.room_id,r.program_id,p.program_code,r.room_name,r.building,r.capacity,r.room_type,r.status
        FROM rooms r LEFT JOIN programs p ON p.program_id=r.program_id
        WHERE r.data_origin='DEMO' ORDER BY r.room_name");
    $availability=rcRows($db,"SELECT room_id,availability_status,TIME_FORMAT(start_time,'%H:%i') AS start_time,TIME_FORMAT(end_time,'%H:%i') AS end_time
        FROM room_availability WHERE academic_period_id=:period AND day_of_week=:day AND data_origin='DEMO'",$params);

$slots=rcRows($db,"SELECT TIME_FORMAT(start_time,'%H:%i') AS start_time,TIME_FORMAT(end_time,'%H:%i') AS end_time
    FROM time_slots WHERE day_of_week=:day AND is_active=1 AND data_origin='DEMO'",
    ['day'=>$day]);

    // All ACTIVE class batches in the period; never look at historical SUPERSEDED meetings.
    $classes=rcRows($db,"SELECT m.meeting_id AS reference_id,m.room_id,p.program_code,
            TIME_FORMAT(m.start_time,'%H:%i') AS start_time,TIME_FORMAT(m.end_time,'%H:%i') AS end_time
        FROM schedule_meetings m JOIN schedule_batches b ON b.batch_id=m.batch_id
        JOIN programs p ON p.program_id=b.program_id
        WHERE b.academic_period_id=:period AND b.status='ACTIVE' AND m.day_of_week=:day AND m.room_id IS NOT NULL",$params);
    // Exam meetings are dated rather than weekly recurring. ALL ACTIVE exam batches in period count.
    $exams=rcRows($db,"SELECT e.exam_meeting_id AS reference_id,e.room_id,p.program_code,
            TIME_FORMAT(e.start_time,'%H:%i') AS start_time,TIME_FORMAT(e.end_time,'%H:%i') AS end_time
        FROM exam_meetings e JOIN exam_batches b ON b.exam_batch_id=e.exam_batch_id
        JOIN programs p ON p.program_id=b.program_id
        WHERE b.academic_period_id=:period AND b.status='ACTIVE' AND e.exam_date=:date AND e.room_id IS NOT NULL",
        ['period'=>$periodId,'date'=>$dateStr]);
    // Phase 6 remains ON HOLD; only genuinely saved ACTIVE special meetings, if any, reserve rooms.
    $specials=rcRows($db,"SELECT sm.special_meeting_id AS reference_id,sm.room_id,p.program_code,
            TIME_FORMAT(sm.start_time,'%H:%i') AS start_time,TIME_FORMAT(sm.end_time,'%H:%i') AS end_time
        FROM special_class_meetings sm JOIN special_classes sc ON sc.special_class_id=sm.special_class_id
        JOIN programs p ON p.program_id=sc.program_id
        WHERE sc.academic_period_id=:period AND sc.status='ACTIVE' AND sm.status='SCHEDULED'
        AND sm.meeting_date=:date AND sm.room_id IS NOT NULL",['period'=>$periodId,'date'=>$dateStr]);
    $report=rcAssess($rooms,$availability,$slots,$classes,$exams,$specials,$start,$end,$programId,$capacity,$type);
    rcReply(200,['success'=>true,'status'=>'ROOM_AVAILABILITY_READY','period'=>$period,'date'=>$dateStr,'day_of_week'=>$day,
        'start_time'=>rcClock($start),'end_time'=>rcClock($end),'filters'=>['program_id'=>$programId,'minimum_capacity'=>$capacity,'room_type'=>$type],
        'summary'=>$report['summary'],'rooms'=>$report['rooms'],'time_slot_covered'=>$report['time_slot_covered'],
        'coverage_note'=>'ACTIVE recurring class meetings are applied by weekday. This does not verify school calendar dates, cancellations, holidays or unsaved DEMO previews. No room is booked by this lookup.',
        'read_only'=>true]);
} catch (RoomCheckException $e) {
    rcReply($e->http,['success'=>false,'status'=>$e->statusCode,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('BCP Module 7 room availability: '.$e->getMessage());
    rcReply(500,['success'=>false,'status'=>'ROOM_AVAILABILITY_ERROR','message'=>'Unable to load room availability. Check the PHP error log; no data was changed.']);
}
