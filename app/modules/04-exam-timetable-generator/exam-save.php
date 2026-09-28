<?php
declare(strict_types=1);
/** Save first ACTIVE DEMO exam batch only. No browser-provided exam assignments. */
require_once __DIR__ . '/exam-common.php';
$pdo=null;$locked=false;$lockName='';$replyHttp=500;$reply=['success'=>false,'status'=>'EXAM_SAVE_FAILED','database_write'=>false];
try {
    exGuard('POST');
    $body=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($body)||!is_string($body['preview_token']??null)) exFail(400,'PREVIEW_TOKEN_REQUIRED','Generate an exam preview first.');
    session_name('BCP_EXAM_DEMO');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','path'=>'/BCP_SCHEDULING/app/modules/04-exam-timetable-generator']);
    session_start();
    $preview=$_SESSION['bcp_exam_preview']??null;
    if (!is_array($preview) || !hash_equals((string)($preview['token']??''),$body['preview_token'])
        || time()-(int)($preview['created_at']??0)>900) exFail(409,'PREVIEW_MISSING_OR_EXPIRED','Preview expired or does not match this session. Generate a new preview.');
    if (!isset($preview['result']['assignments']) || !is_array($preview['result']['assignments'])) exFail(409,'PREVIEW_INVALID','Missing server-held preview.');
    $pdo=getDatabase();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $periodId=(int)$preview['period_id'];
    $lockName='bcp_exam_save_period_'.$periodId;
    $lock=$pdo->prepare('SELECT GET_LOCK(:name,10)');$lock->execute(['name'=>$lockName]);
    if ((int)$lock->fetchColumn()!==1) exFail(409,'EXAM_SAVE_BUSY','Another exam save is in progress. Please retry.');
    $locked=true;
    $pdo->beginTransaction();
    // Re-read ALL DB facts while holding the period-specific saving mutex.
    $programCode=(string)($preview['program']??'BSIT');
    $fresh=exBuildInput($pdo,$periodId,$preview['dates'],$preview['window'],null,$programCode);
    if ((int)$fresh['class_batch_id']!==(int)$preview['class_batch_id']
        || !hash_equals((string)$preview['input_hash'],exJsonHash($fresh))) {
        exFail(409,'STALE_EXAM_PREVIEW','Class schedules, students, teachers, rooms, existing exams or other inputs have changed. Generate a fresh preview.');
    }
    foreach($fresh['active_exam_batches'] as $old) {
        if ((int)$old['program_id']===(int)$fresh['program']['program_id'] && $old['exam_label']==='DEMO-EXAM') {
            exFail(409,'EXAM_ALREADY_SAVED','An ACTIVE '. $programCode .' DEMO exam batch already exists. No duplicate was saved.');
        }
    }
    // Independent PHP audit from current DB facts + stored server result.
    exValidate($fresh,$preview['result']);
    $dates=$fresh['input']['exam_dates'];
    $ins=$pdo->prepare("INSERT INTO exam_batches (academic_period_id,program_id,class_batch_id,exam_label,data_origin,exam_day_1,exam_day_2,exam_day_3,status) VALUES (:period,:program,:class_batch,'DEMO-EXAM','DEMO',:d1,:d2,:d3,'ACTIVE')");
    $ins->execute(['period'=>$periodId,'program'=>$fresh['program']['program_id'],'class_batch'=>$fresh['class_batch_id'],
        'd1'=>$dates[0],'d2'=>$dates[1],'d3'=>$dates[2]]);
    $examBatchId=(int)$pdo->lastInsertId();
    $insertExam=$pdo->prepare("INSERT INTO exam_meetings (exam_batch_id,section_subject_id,proctor_id,room_id,exam_day,exam_date,start_time,end_time) VALUES (:batch,:section_subject,:proctor,:room,:day,:date,:start,:end)");
    foreach($preview['result']['assignments'] as $row) {
        $insertExam->execute(['batch'=>$examBatchId,'section_subject'=>$row['section_subject_id'],
            'proctor'=>$row['proctor_id'],'room'=>$row['room_id'],'day'=>$row['exam_day'],'date'=>$row['exam_date'],
            'start'=>$row['start_time'],'end'=>$row['end_time']]);
    }
    $saved=exRows($pdo,'SELECT COUNT(*) AS total FROM exam_meetings WHERE exam_batch_id=:batch',['batch'=>$examBatchId]);
    if ((int)$saved[0]['total']!==count($preview['result']['assignments'])) exFail(500,'INCOMPLETE_SAVE','Exam insertion was incomplete; transaction rolled back.');
    $pdo->commit();
    unset($_SESSION['bcp_exam_preview']);session_write_close();
    $replyHttp=200;$reply=['success'=>true,'status'=>'EXAM_SAVED','program'=>$programCode,'exam_batch_id'=>$examBatchId,'class_batch_id'=>$fresh['class_batch_id'],
        'saved_exams'=>(int)$saved[0]['total'],'data_origin'=>'DEMO','database_write'=>true];
} catch (ExamFailure $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $replyHttp=$e->http;$reply=['success'=>false,'status'=>$e->errorCode,'message'=>$e->getMessage(),'database_write'=>false];
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('BCP exam save: '.$e->getMessage());
    $replyHttp=500;$reply=['success'=>false,'status'=>'EXAM_SAVE_FAILED','message'=>'Exam save failed. Check PHP error log. No partial timetable should be saved.','database_write'=>false];
} finally {
    if ($locked && $pdo instanceof PDO) {
        try{$release=$pdo->prepare('SELECT RELEASE_LOCK(:name)');$release->execute(['name'=>$lockName]);}catch(Throwable $ignored){}
    }
}

exReply($replyHttp,$reply);
