<?php
declare(strict_types=1);
/** Read-only ACTIVE DEMO exam timetable for the selected program. Does not rerun the solver. */
require_once __DIR__ . '/exam-common.php';
try {
    exGuard('GET');
    $periodId=filter_var($_GET['period_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$periodId) exFail(400,'PERIOD_REQUIRED','Choose an academic period.');
    $programCode=strtoupper(trim((string)($_GET['program']??'BSIT')));
    if (!preg_match('/^[A-Z0-9-]{2,30}$/',$programCode)) exFail(400,'INVALID_PROGRAM','Choose a valid program.');
    $pdo=getDatabase();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $batches=exRows($pdo,"SELECT eb.exam_batch_id,eb.class_batch_id,eb.exam_day_1,eb.exam_day_2,eb.exam_day_3,eb.created_at,
        ap.academic_period_id,ap.academic_year,ap.semester,p.program_code FROM exam_batches eb
        JOIN academic_periods ap ON ap.academic_period_id=eb.academic_period_id
        JOIN programs p ON p.program_id=eb.program_id
        WHERE eb.academic_period_id=:period AND p.program_code=:program AND eb.exam_label='DEMO-EXAM' AND eb.data_origin='DEMO' AND eb.status='ACTIVE' ORDER BY eb.exam_batch_id",['period'=>$periodId,'program'=>$programCode]);
    if (count($batches)>1) exFail(409,'MULTIPLE_ACTIVE_EXAM_BATCHES','More than one ACTIVE DEMO exam batch exists. Review the database.');
    if (!$batches) exReply(200,['success'=>true,'status'=>'NO_SAVED_EXAMS','has_saved_exams'=>false,'database_write'=>false]);
    $b=$batches[0];
    $assignments=exRows($pdo,"SELECT em.exam_meeting_id,em.section_subject_id,ss.section_id,sec.section_code,sec.section_type,sec.year_level,
        ss.subject_id,sub.subject_code,sub.subject_title,em.proctor_id,t.teacher_name AS proctor_name,em.room_id,r.room_name,
        em.exam_day,DATE_FORMAT(em.exam_date,'%Y-%m-%d') AS exam_date,TIME_FORMAT(em.start_time,'%H:%i') AS start_time,TIME_FORMAT(em.end_time,'%H:%i') AS end_time
        FROM exam_meetings em JOIN section_subjects ss ON ss.section_subject_id=em.section_subject_id
        JOIN sections sec ON sec.section_id=ss.section_id JOIN subjects sub ON sub.subject_id=ss.subject_id
        JOIN teachers t ON t.teacher_id=em.proctor_id JOIN rooms r ON r.room_id=em.room_id
        WHERE em.exam_batch_id=:batch ORDER BY sec.year_level,sec.section_code,em.exam_day,em.start_time,em.exam_meeting_id",['batch'=>$b['exam_batch_id']]);
    if (!$assignments) exFail(409,'EMPTY_ACTIVE_EXAMS','Saved exam batch contains no readable examinations.');
    foreach($assignments as &$a){foreach(['exam_meeting_id','section_subject_id','section_id','year_level','subject_id','proctor_id','room_id','exam_day'] as $k)$a[$k]=(int)$a[$k];}unset($a);
    $dates=[$b['exam_day_1'],$b['exam_day_2'],$b['exam_day_3']];
    // DB DATE is normally returned as YYYY-MM-DD by PDO MySQL.
    exReply(200,['success'=>true,'status'=>'EXAM_SAVED_READY','has_saved_exams'=>true,
        'exam_batch_id'=>(int)$b['exam_batch_id'],'class_batch_id'=>(int)$b['class_batch_id'],
        'period'=>['academic_period_id'=>(int)$b['academic_period_id'],'academic_year'=>$b['academic_year'],'semester'=>(int)$b['semester']],
        'program'=>$b['program_code'],'exam_dates'=>$dates,'required_exams'=>count($assignments),'returned_exams'=>count($assignments),
        'assignments'=>$assignments,'saved_at'=>$b['created_at'],'database_write'=>false,
        'independent_audit_rerun'=>false]);
} catch (ExamFailure $e) {
    exReply($e->http,['success'=>false,'status'=>$e->errorCode,'message'=>$e->getMessage(),'database_write'=>false]);
} catch (Throwable $e) {
    error_log('BCP saved exams: '.$e->getMessage());
    exReply(500,['success'=>false,'status'=>'EXAM_LOAD_FAILED','message'=>'Unable to load saved exams. Check PHP error log.','database_write'=>false]);
}
