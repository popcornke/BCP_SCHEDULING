<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/python.php';
/** Module 4 DEMO: audited proposed exam replacement, NO writes to MySQL. */
require_once __DIR__ . '/exam-common.php';
try {
    exGuard('POST');
    set_time_limit(155);
    $body=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($body) || !is_string($body['program']??null)) {
        exFail(400,'PROGRAM_REQUIRED','Choose a program for examination replacement.');
    }
    $programCode=strtoupper(trim((string)$body['program']));
    $periodId=filter_var($body['period_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $oldId=filter_var($body['replace_exam_batch_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$periodId || !$oldId || !is_array($body['exam_dates']??null)) {
        exFail(400,'REPLACEMENT_INPUT_REQUIRED','Select the period, current exam batch and three examination dates.');
    }
    $window=(string)($body['exam_window']??'06-21');
    $pdo=getDatabase();
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $snapshot=exBuildInput($pdo,(int)$periodId,$body['exam_dates'],$window,(int)$oldId,$programCode);
    if (!function_exists('curl_init')) exFail(500,'CURL_UNAVAILABLE','Enable PHP cURL for the Python exam optimizer.');
    $curl=curl_init(pythonBaseUrl() . '/api/exams/preview');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>130,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($snapshot['input'],JSON_THROW_ON_ERROR)]);
    $raw=curl_exec($curl); $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $error=curl_error($curl); curl_close($curl);
    if (!is_string($raw) || $http!==200) {
        error_log('BCP exam replacement solver: HTTP '.$http.' '.$error.' '.$raw);
        exFail(502,'EXAM_SOLVER_UNAVAILABLE','Python exam solver did not return a successful timetable. Check FastAPI logs.');
    }
    $output=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($output)) exFail(502,'INVALID_SOLVER_OUTPUT','The optimizer returned an invalid response.');
    if (($output['success']??false)!==true) exReply(422,$output+['database_write'=>false]);
    // Independent PHP audit against FRESH source facts, excluding ONLY old exams.
    exValidate($snapshot,$output);
    session_name('BCP_EXAM_DEMO');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict',
        'path'=>'/BCP_SCHEDULING/app/modules/04-exam-timetable-generator']);
    session_start();
    $token=bin2hex(random_bytes(32));
    $_SESSION['bcp_exam_replacement_preview']=[
        'token'=>$token,'created_at'=>time(),'period_id'=>(int)$periodId,'program'=>$programCode,
        'old_batch_id'=>(int)$oldId,'dates'=>$snapshot['input']['exam_dates'],
        'window'=>$window,'class_batch_id'=>$snapshot['class_batch_id'],
        'input_hash'=>exJsonHash($snapshot),'result'=>$output,
    ];
    session_write_close();
    $output['period']=$snapshot['period'];
    $output['program']=$programCode;
    $output['program_name']=$snapshot['program']['program_name'];
    $output['class_batch_id']=$snapshot['class_batch_id'];
    $output['replace_exam_batch_id']=(int)$oldId;
    $output['replacement_preview']=true;
    $output['preview_token']=$token;
    $output['database_write']=false;
    exReply(200,$output);
} catch (ExamFailure $e) {
    exReply($e->http,['success'=>false,'status'=>$e->errorCode,
        'message'=>$e->getMessage(),'database_write'=>false]);
} catch (Throwable $e) {
    error_log('BCP exam replacement preview: '.$e->getMessage());
    exReply(500,['success'=>false,'status'=>'EXAM_REPLACEMENT_PREVIEW_FAILED',
        'message'=>'Unable to build a safe replacement preview. Check PHP error log; no database changes were made.',
        'database_write'=>false]);
}
