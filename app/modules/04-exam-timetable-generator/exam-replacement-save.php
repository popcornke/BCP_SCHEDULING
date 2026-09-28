<?php
declare(strict_types=1);
/** Module 4 DEMO: replace exactly one ACTIVE saved exam batch atomically. */
require_once __DIR__ . '/exam-common.php';
$pdo=null; $held=false; $lockName='';
$replyHttp=500;
$reply=['success'=>false,'status'=>'EXAM_REPLACEMENT_FAILED','database_write'=>false];
try {
    exGuard('POST');
    $body=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($body) || !is_string($body['preview_token']??null)
        || strlen($body['preview_token'])!==64) {
        exFail(400,'PREVIEW_TOKEN_REQUIRED','Generate and review a replacement preview first.');
    }
    session_name('BCP_EXAM_DEMO');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict',
        'path'=>'/BCP_SCHEDULING/app/modules/04-exam-timetable-generator']);
    session_start();
    $preview=$_SESSION['bcp_exam_replacement_preview']??null;
    if (!is_array($preview)
        || !hash_equals((string)($preview['token']??''),$body['preview_token'])
        || time()-(int)($preview['created_at']??0)>900) {
        exFail(409,'REPLACEMENT_PREVIEW_EXPIRED','Replacement preview expired or does not match this session. Generate a new preview.');
    }
    if (!is_array($preview['result']['assignments']??null)) {
        exFail(409,'REPLACEMENT_PREVIEW_INVALID','The server has no verified replacement assignments.');
    }
    $periodId=(int)$preview['period_id'];
    $oldId=(int)$preview['old_batch_id'];
    $pdo=getDatabase();
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    // Same period-wide advisory lock used by the EXISTING first-save API.
    $lockName='bcp_exam_save_period_'.$periodId;
    $lock=$pdo->prepare('SELECT GET_LOCK(:name,10)');
    $lock->execute(['name'=>$lockName]);
    if ((int)$lock->fetchColumn()!==1) exFail(409,'EXAM_SAVE_BUSY','Another exam save or replacement is in progress. Retry.');
    $held=true;
    $pdo->beginTransaction();
    // Lock current batch records to serialize edits and detect changes to
    // ACTIVE ownership, including other program batches in this period.
    $batchLock=$pdo->prepare("SELECT exam_batch_id,program_id,class_batch_id,exam_label,data_origin,status
        FROM exam_batches WHERE academic_period_id=:period ORDER BY exam_batch_id FOR UPDATE");
    $batchLock->execute(['period'=>$periodId]);
    $lockedBatches=$batchLock->fetchAll(PDO::FETCH_ASSOC);
    $meetingLock=$pdo->prepare("SELECT em.exam_meeting_id,em.exam_batch_id
        FROM exam_meetings em JOIN exam_batches eb ON eb.exam_batch_id=em.exam_batch_id
        WHERE eb.academic_period_id=:period AND eb.status='ACTIVE'
        ORDER BY em.exam_meeting_id FOR UPDATE");
    $meetingLock->execute(['period'=>$periodId]);
    $meetingLock->fetchAll(PDO::FETCH_ASSOC);
    $classLock=$pdo->prepare("SELECT batch_id,program_id,status FROM schedule_batches
        WHERE academic_period_id=:period AND status='ACTIVE' ORDER BY batch_id FOR UPDATE");
    $classLock->execute(['period'=>$periodId]);
    $classLock->fetchAll(PDO::FETCH_ASSOC);
    $programCode=(string)($preview['program']??'BSIT');
    $fresh=exBuildInput($pdo,$periodId,$preview['dates'],$preview['window'],$oldId,$programCode);
    if ((int)$fresh['class_batch_id']!==(int)$preview['class_batch_id']
        || !hash_equals((string)$preview['input_hash'],exJsonHash($fresh))) {
        exFail(409,'STALE_EXAM_REPLACEMENT','Saved exams, students, class schedule or resources changed. Regenerate the replacement preview.');
    }
    // The old batch is ACTIVE and complete under the same period-wide lock.
    $matching=array_values(array_filter($lockedBatches,static fn($b) =>
        (int)$b['exam_batch_id']===$oldId && $b['status']==='ACTIVE'
        && $b['exam_label']==='DEMO-EXAM' && $b['data_origin']==='DEMO'
        && (int)$b['program_id']===(int)$fresh['program']['program_id']));
    if (count($matching)!==1) exFail(409,'EXAM_BASELINE_CHANGED','The original ACTIVE exam batch changed; reload the page.');
    exValidate($fresh,$preview['result']);
    $dates=$fresh['input']['exam_dates'];
    // This order matters: unique ACTIVE index permits a new ACTIVE row only
    // AFTER superseding the old row, and rollback restores the old row on error.
    $update=$pdo->prepare("UPDATE exam_batches SET status='SUPERSEDED'
        WHERE exam_batch_id=:old AND academic_period_id=:period AND program_id=:program
          AND exam_label='DEMO-EXAM' AND data_origin='DEMO' AND status='ACTIVE'");
    $update->execute(['old'=>$oldId,'period'=>$periodId,'program'=>$fresh['program']['program_id']]);
    if ($update->rowCount()!==1) exFail(409,'EXAM_BASELINE_CHANGED','Previous exam batch is no longer ACTIVE.');
    $insert=$pdo->prepare("INSERT INTO exam_batches
        (academic_period_id,program_id,class_batch_id,exam_label,data_origin,exam_day_1,exam_day_2,exam_day_3,status)
        VALUES (:period,:program,:class_batch,'DEMO-EXAM','DEMO',:d1,:d2,:d3,'ACTIVE')");
    $insert->execute(['period'=>$periodId,'program'=>$fresh['program']['program_id'],
        'class_batch'=>$fresh['class_batch_id'],'d1'=>$dates[0],'d2'=>$dates[1],'d3'=>$dates[2]]);
    $newId=(int)$pdo->lastInsertId();
    $insertMeeting=$pdo->prepare("INSERT INTO exam_meetings
        (exam_batch_id,section_subject_id,proctor_id,room_id,exam_day,exam_date,start_time,end_time)
        VALUES (:batch,:section_subject,:proctor,:room,:day,:date,:start,:end)");
    foreach ($preview['result']['assignments'] as $row) {
        $insertMeeting->execute(['batch'=>$newId,
            'section_subject'=>$row['section_subject_id'],'proctor'=>$row['proctor_id'],
            'room'=>$row['room_id'],'day'=>$row['exam_day'],'date'=>$row['exam_date'],
            'start'=>$row['start_time'],'end'=>$row['end_time']]);
    }
    $count=exRows($pdo,'SELECT COUNT(*) AS n FROM exam_meetings WHERE exam_batch_id=:id',['id'=>$newId]);
    if ((int)$count[0]['n']!==count($preview['result']['assignments'])) {
        exFail(500,'INCOMPLETE_EXAM_REPLACEMENT','Not all examinations were saved; rolling back.');
    }
    $pdo->commit();
    unset($_SESSION['bcp_exam_replacement_preview']);
    session_write_close();
    $replyHttp=200;
    $reply=['success'=>true,'status'=>'EXAM_REPLACED','program'=>$programCode,'previous_exam_batch_id'=>$oldId,
        'exam_batch_id'=>$newId,'class_batch_id'=>$fresh['class_batch_id'],
        'saved_exams'=>(int)$count[0]['n'],'data_origin'=>'DEMO','database_write'=>true];
} catch (ExamFailure $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $replyHttp=$e->http;
    $reply=['success'=>false,'status'=>$e->errorCode,'message'=>$e->getMessage(),'database_write'=>false];
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('BCP exam replacement save: '.$e->getMessage());
    $replyHttp=500;
    $reply=['success'=>false,'status'=>'EXAM_REPLACEMENT_FAILED',
        'message'=>'Exam replacement failed; the original exam batch was not intentionally changed. Check PHP error log.',
        'database_write'=>false];
} finally {
    if ($held && $pdo instanceof PDO) {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(:name)');$release->execute(['name'=>$lockName]); }
        catch (Throwable $ignored) {}
    }
}
exReply($replyHttp,$reply);
