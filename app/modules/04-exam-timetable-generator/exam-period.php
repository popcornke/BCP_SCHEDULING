<?php
declare(strict_types=1);
/** Module 4: manage one BCP-wide exam date window per academic period. */
require_once __DIR__ . '/exam-common.php';

$pdo=null;$lockName='';$held=false;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        exGuard('GET');
        $periodId=filter_var($_GET['period_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$periodId) exFail(400,'PERIOD_REQUIRED','Choose an academic period.');
        $pdo=getDatabase();
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $state=exUnifiedExamPeriod($pdo,(int)$periodId);
        exReply(200,[
            'success'=>true,
            'status'=>'UNIFIED_EXAM_PERIOD_READY',
            'period'=>$state['period'],
            'exam_dates'=>$state['exam_dates'],
            'configured'=>$state['configured'],
            'locked'=>$state['locked'],
            'source'=>$state['source'],
            'active_exam_batch_count'=>$state['active_exam_batch_count'],
            'active_programs'=>$state['active_programs'],
            'database_write'=>false,
        ]);
    }

    exGuard('POST');
    $body=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    $periodId=filter_var($body['period_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$periodId || !is_array($body['exam_dates']??null)) {
        exFail(400,'INVALID_EXAM_PERIOD','Choose an academic period and three examination dates.');
    }
    $dates=exDates($body['exam_dates']);

    $pdo=getDatabase();
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $lockName='bcp_exam_save_period_'.(int)$periodId;
    $lock=$pdo->prepare('SELECT GET_LOCK(:name,10)');
    $lock->execute(['name'=>$lockName]);
    if ((int)$lock->fetchColumn()!==1) exFail(409,'EXAM_PERIOD_BUSY','Another exam save or date update is in progress. Retry.');
    $held=true;

    $pdo->beginTransaction();
    $batchLock=$pdo->prepare("SELECT exam_batch_id,program_id,exam_day_1,exam_day_2,exam_day_3,status
        FROM exam_batches WHERE academic_period_id=:period ORDER BY exam_batch_id FOR UPDATE");
    $batchLock->execute(['period'=>(int)$periodId]);
    $batchLock->fetchAll(PDO::FETCH_ASSOC);

    $state=exUnifiedExamPeriod($pdo,(int)$periodId);
    if ($state['locked']) {
        if (!is_array($state['exam_dates']) || $dates!==$state['exam_dates']) {
            exFail(409,'EXAM_DATES_LOCKED',
                'Examination dates are locked because at least one ACTIVE program exam timetable already exists. All programs must use the same BCP exam dates.');
        }
        $pdo->commit();
        $reply=[
            'success'=>true,
            'status'=>'UNIFIED_EXAM_DATES_ALREADY_LOCKED',
            'exam_dates'=>$state['exam_dates'],
            'configured'=>true,
            'locked'=>true,
            'active_exam_batch_count'=>$state['active_exam_batch_count'],
            'active_programs'=>$state['active_programs'],
            'database_write'=>false,
        ];
    } else {
        $exists=exRows($pdo,"SELECT academic_period_calendar_id FROM academic_period_calendars
            WHERE academic_period_id=:period LIMIT 1",['period'=>(int)$periodId]);
        if ($exists) {
            $st=$pdo->prepare("UPDATE academic_period_calendars
                SET exam_day_1=:d1,exam_day_2=:d2,exam_day_3=:d3
                WHERE academic_period_id=:period");
            $st->execute(['d1'=>$dates[0],'d2'=>$dates[1],'d3'=>$dates[2],'period'=>(int)$periodId]);
        } else {
            $st=$pdo->prepare("INSERT INTO academic_period_calendars
                (academic_period_id,exam_day_1,exam_day_2,exam_day_3,calendar_status)
                VALUES (:period,:d1,:d2,:d3,'PENDING')");
            $st->execute(['period'=>(int)$periodId,'d1'=>$dates[0],'d2'=>$dates[1],'d3'=>$dates[2]]);
        }
        $pdo->commit();
        $reply=[
            'success'=>true,
            'status'=>'UNIFIED_EXAM_DATES_SAVED',
            'exam_dates'=>$dates,
            'configured'=>true,
            'locked'=>false,
            'active_exam_batch_count'=>0,
            'active_programs'=>[],
            'database_write'=>true,
        ];
    }

    if ($held) {
        $release=$pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute(['name'=>$lockName]);
        $held=false;
    }
    exReply(200,$reply);
} catch (ExamFailure $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if ($held && $pdo instanceof PDO) {
        try{$release=$pdo->prepare('SELECT RELEASE_LOCK(:name)');$release->execute(['name'=>$lockName]);}catch(Throwable $ignored){}
    }
    exReply($e->http,[
        'success'=>false,
        'status'=>$e->errorCode,
        'message'=>$e->getMessage(),
        'database_write'=>false,
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if ($held && $pdo instanceof PDO) {
        try{$release=$pdo->prepare('SELECT RELEASE_LOCK(:name)');$release->execute(['name'=>$lockName]);}catch(Throwable $ignored){}
    }
    error_log('BCP unified exam period: '.$e->getMessage());
    exReply(500,[
        'success'=>false,
        'status'=>'UNIFIED_EXAM_PERIOD_FAILED',
        'message'=>'Unable to read or update the unified examination period. Check the PHP error log.',
        'database_write'=>false,
    ]);
}
