<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';
authRequire(true);
/** Module 6 Phase 6A: read-only catalog and candidate weekly date expansion. NO save path. */
require_once __DIR__ . '/../../config/database.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function scReply(int $http, array $body): never {
    http_response_code($http);
    echo json_encode($body + ['database_write' => false], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function scRows(PDO $db, string $query, array $params=[]): array {
    $st=$db->prepare($query); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC);
}
try {
    if (($_SERVER['REQUEST_METHOD']??'GET') !== 'GET') scReply(405,['success'=>false,'status'=>'READ_ONLY_PHASE']);
    $db=getDatabase(); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $period=(int)($_GET['period_id']??1);
    if ($period<1) scReply(400,['success'=>false,'status'=>'INVALID_PERIOD']);
    $periodRows=scRows($db,'SELECT academic_period_id,academic_year,semester,period_status FROM academic_periods WHERE academic_period_id=?',[$period]);
    if (!$periodRows || $periodRows[0]['period_status']!=='DEMO') scReply(422,['success'=>false,'status'=>'DEMO_PERIOD_REQUIRED']);
    $programs=scRows($db,"SELECT p.program_id,p.program_code,p.program_name FROM programs p WHERE p.is_active=1 ORDER BY p.program_code");
    $subjects=scRows($db,'SELECT subject_id,program_id,subject_code,subject_title FROM subjects WHERE is_active=1 AND semester=? ORDER BY subject_code',[$periodRows[0]['semester']]);
    $teachers=scRows($db,"SELECT teacher_id,program_id,teacher_name,max_daily_hours,max_weekly_hours FROM teachers WHERE status='ACTIVE' AND data_origin='DEMO' ORDER BY teacher_name");
    $sections=scRows($db,"SELECT section_id,program_id,section_code,section_type,year_level FROM sections WHERE academic_period_id=? AND is_active=1 ORDER BY section_code",[$period]);
    $slots=scRows($db,"SELECT time_slot_id,day_of_week,start_time,end_time FROM time_slots WHERE is_active=1 AND data_origin='DEMO' ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time",[]);
    $types=['REMEDIAL','IRREGULAR','OCTOBERIAN'];
    scReply(200,['success'=>true,'status'=>'SPECIAL_CLASS_FOUNDATION_READY','phase'=>'READ_ONLY_CATALOG','period'=>$periodRows[0],'programs'=>$programs,'subjects'=>$subjects,'teachers'=>$teachers,'sections'=>$sections,'time_slots'=>$slots,'types'=>$types,'recurrence'=>'WEEKLY','duration_policy'=>'PENDING_APPROVAL','octoberian_policy'=>'GENERIC_ONLY','saving_enabled'=>false]);
} catch (Throwable $e) {
    error_log('BCP Special Class Phase 6A: '.$e->getMessage());
    scReply(500,['success'=>false,'status'=>'SPECIAL_CLASS_CATALOG_ERROR','message'=>'Unable to load special class catalog. Check PHP error log.']);
}
