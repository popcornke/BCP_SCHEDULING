<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire(true);
/**
 * BCP Module 8, Phase 8A: read-only source/target readiness checker.
 * Never creates periods, sections, batch records, or schedule meetings.
 */
require_once __DIR__ . '/../../config/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

final class CloneCheckError extends RuntimeException {
    public function __construct(public int $httpStatus, public string $statusCode, string $message) {
        parent::__construct($message);
    }
}
function ccFail(int $http, string $code, string $message): never {
    throw new CloneCheckError($http, $code, $message);
}
function ccReply(int $http, array $payload): never {
    http_response_code($http);
    echo json_encode($payload + [
        'read_only'=>true, 'database_write'=>false, 'saving_enabled'=>false,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}
function ccRows(PDO $db, string $sql, array $bind=[]): array {
    $q=$db->prepare($sql);
    $q->execute($bind);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function ccPositive(mixed $raw, string $name): int {
    if (!is_string($raw) && !is_int($raw)) ccFail(400,'INVALID_ID','Select a valid '.$name.'.');
    $raw=(string)$raw;
    if (!preg_match('/^[1-9][0-9]{0,9}$/D',$raw)) ccFail(400,'INVALID_ID','Select a valid '.$name.'.');
    $n=(int)$raw;
    if ($n>2147483647) ccFail(400,'INVALID_ID','Select a valid '.$name.'.');
    return $n;
}
function ccCatalog(PDO $db): array {
    $periods=ccRows($db,"SELECT academic_period_id, academic_year,semester,period_status
        FROM academic_periods WHERE period_status='DEMO' ORDER BY academic_period_id DESC");
    $programs=ccRows($db,"SELECT program_id,program_code,program_name
        FROM programs WHERE is_active=1 AND education_level='College' ORDER BY program_code");
    $batches=ccRows($db,"SELECT b.batch_id,b.academic_period_id,b.program_id,
        p.program_code,p.program_name,ap.academic_year,ap.semester,b.status,b.data_origin,
        COUNT(m.meeting_id) AS meeting_count
        FROM schedule_batches b
        JOIN academic_periods ap ON ap.academic_period_id=b.academic_period_id
        JOIN programs p ON p.program_id=b.program_id
        LEFT JOIN schedule_meetings m ON m.batch_id=b.batch_id
        WHERE b.status='ACTIVE' AND b.data_origin='DEMO' AND ap.period_status='DEMO'
        GROUP BY b.batch_id,b.academic_period_id,b.program_id,p.program_code,p.program_name,
                 ap.academic_year,ap.semester,b.status,b.data_origin
        ORDER BY b.batch_id DESC");
    foreach ($batches as &$batch) {
        foreach (['batch_id','academic_period_id','program_id','semester','meeting_count'] as $field) {
            $batch[$field]=(int)$batch[$field];
        }
    }
    unset($batch);
    return ['periods'=>$periods,'programs'=>$programs,'source_batches'=>$batches];
}
function ccAssessment(PDO $db, array $catalog, int $sourceId, int $targetPeriodId): array {
    $source=null;
    foreach ($catalog['source_batches'] as $row) {
        if ($row['batch_id']===$sourceId) { $source=$row; break; }
    }
    if ($source===null) ccFail(404,'SOURCE_NOT_FOUND','Select an existing ACTIVE DEMO source batch.');
    $target=null;
    foreach ($catalog['periods'] as $row) {
        if ((int)$row['academic_period_id']===$targetPeriodId) { $target=$row; break; }
    }
    if ($target===null) ccFail(404,'TARGET_NOT_FOUND','The target DEMO period does not exist. Module 8 will not create a period automatically.');
    if ($targetPeriodId===$source['academic_period_id']) {
        ccFail(422,'SAME_PERIOD_NOT_ALLOWED','The source and target academic period must be different.');
    }
    $pid=$source['program_id'];
    $issues=[];
    if ((int)$source['meeting_count']<1) $issues[]=['code'=>'EMPTY_SOURCE','message'=>'The selected source batch has no saved meetings.'];
    if ((int)$target['semester']!==(int)$source['semester']) {
        $issues[]=['code'=>'SEMESTER_MISMATCH','message'=>'Target semester differs from source. Subject and curriculum equivalence require a separate approved mapping; cloning is blocked.'];
    }
    $targetBatch=ccRows($db,"SELECT batch_id,status,data_origin FROM schedule_batches
        WHERE academic_period_id=:period AND program_id=:program AND status='ACTIVE' ORDER BY batch_id",
        ['period'=>$targetPeriodId,'program'=>$pid]);
    if ($targetBatch) $issues[]=['code'=>'TARGET_ALREADY_HAS_ACTIVE_BATCH',
        'message'=>'The target program already has an ACTIVE timetable. Replacing it requires a separate safe replacement workflow.'];
    $targetSections=ccRows($db,"SELECT section_id,section_code,year_level,section_type,is_active
        FROM sections WHERE academic_period_id=:period AND program_id=:program AND data_origin='DEMO'
        ORDER BY section_code,section_type",['period'=>$targetPeriodId,'program'=>$pid]);
    if (!$targetSections) $issues[]=['code'=>'NO_TARGET_SECTIONS',
        'message'=>'No official target-period sections are present for this program. Section membership will never be copied or created automatically.'];
    $sourceSections=ccRows($db,"SELECT DISTINCT s.section_id,s.section_code,s.year_level,s.section_type
        FROM schedule_meetings m
        JOIN section_subjects ss ON ss.section_subject_id=m.section_subject_id
        JOIN sections s ON s.section_id=ss.section_id
        WHERE m.batch_id=:batch ORDER BY s.section_code,s.section_type",
        ['batch'=>$sourceId]);
    $targetBySignature=[];
    foreach ($targetSections as $s) {
        $signature=$s['section_code'].'|'.$s['year_level'].'|'.$s['section_type'];
        $targetBySignature[$signature][]=$s;
    }
    $sectionMap=[];$sourceSectionIds=[];
    foreach ($sourceSections as $s) {
        $sourceSectionIds[]=(int)$s['section_id'];
        $signature=$s['section_code'].'|'.$s['year_level'].'|'.$s['section_type'];
        $matches=array_values(array_filter($targetBySignature[$signature]??[],static fn($row)=>(int)$row['is_active']===1));
        $sectionMap[]=['source_section_id'=>(int)$s['section_id'],'section_code'=>$s['section_code'],
            'year_level'=>(int)$s['year_level'],'section_type'=>$s['section_type'],
            'target_section_id'=>count($matches)===1?(int)$matches[0]['section_id']:null,
            'mapping_status'=>count($matches)===1?'EXACT_CODE_TYPE_YEAR_MATCH':(count($matches)===0?'MISSING':'AMBIGUOUS')];
    }
    $unmatched=count(array_filter($sectionMap,static fn($s)=>$s['mapping_status']!=='EXACT_CODE_TYPE_YEAR_MATCH'));
    if ($unmatched) $issues[]=['code'=>'SECTION_MAPPING_INCOMPLETE',
        'message'=>"{$unmatched} source sections have no unique matching active target section with the same code, type and year level."];
    // Map source section-subject pairs against separately configured target section-subject pairs.
    // This is a strict inventory check, not a final teacher/room/time/curriculum validator.
    $sourcePairs=ccRows($db,"SELECT DISTINCT ss.section_subject_id,s.section_id,s.section_code,s.section_type,s.year_level,
        sub.subject_code,sub.subject_id
        FROM schedule_meetings m
        JOIN section_subjects ss ON ss.section_subject_id=m.section_subject_id
        JOIN sections s ON s.section_id=ss.section_id
        JOIN subjects sub ON sub.subject_id=ss.subject_id
        WHERE m.batch_id=:batch ORDER BY s.section_code,sub.subject_code",
        ['batch'=>$sourceId]);
    $targetPairs=ccRows($db,"SELECT ss.section_subject_id,s.section_id,s.section_code,s.section_type,s.year_level,
        sub.subject_code,sub.subject_id
        FROM section_subjects ss
        JOIN sections s ON s.section_id=ss.section_id
        JOIN subjects sub ON sub.subject_id=ss.subject_id
        WHERE s.academic_period_id=:period AND s.program_id=:program
          AND s.is_active=1 AND s.data_origin='DEMO'
        ORDER BY s.section_code,sub.subject_code",
        ['period'=>$targetPeriodId,'program'=>$pid]);
    $lookup=[];
    foreach ($targetPairs as $pair) {
        $key=implode('|',[$pair['section_code'],$pair['section_type'],$pair['year_level'],$pair['subject_code']]);
        $lookup[$key][]=$pair;
    }
    $pairMap=[];
    foreach ($sourcePairs as $pair) {
        $key=implode('|',[$pair['section_code'],$pair['section_type'],$pair['year_level'],$pair['subject_code']]);
        $matching=$lookup[$key]??[];
        $pairMap[]=['source_section_subject_id'=>(int)$pair['section_subject_id'],
            'section_code'=>$pair['section_code'],'subject_code'=>$pair['subject_code'],
            'target_section_subject_id'=>count($matching)===1?(int)$matching[0]['section_subject_id']:null,
            'mapping_status'=>count($matching)===1?'EXACT_CODE_MATCH':(count($matching)===0?'MISSING':'AMBIGUOUS')];
    }
    $missingPairs=count(array_filter($pairMap,static fn($pair)=>$pair['mapping_status']!=='EXACT_CODE_MATCH'));
    if ($missingPairs) $issues[]=['code'=>'SUBJECT_MAPPING_INCOMPLETE',
        'message'=>"{$missingPairs} source section-subject pairs have no unique target-period equivalent."];
    // Teaching authorization, target teacher and room availability, capacity, student membership,
    // other ACTIVE programs, exam dates, room policy, periods' official calendar, etc. require
    // fresh independent end-to-end validation before a hypothetical future save.
    $requires=['Teacher authorization and target teaching load','Teacher and room availability in target period',
        'Room capacity, room type and program rules','Target students, including Cluster and Major memberships',
        'Full school-wide ACTIVE timetable and exam/special booking conflict audit',
        'Target curriculum version and semester-specific subject requirements',
        'School calendar, holidays, time slots and break policies','Independent audit and transactional save/replacement'];
    return ['success'=>true,'status'=>'CLONE_READINESS_CHECKED','source'=>$source,'target_period'=>$target,
        'target_active_batches'=>$targetBatch,'source_section_count'=>count($sourceSections),
        'source_section_subject_count'=>count($sourcePairs),'source_meeting_count'=>(int)$source['meeting_count'],
        'target_section_count'=>count($targetSections),'section_mapping'=>$sectionMap,
        'section_subject_mapping'=>$pairMap,'blockers'=>$issues,'unverified_requirements'=>$requires,
        'mapping_inventory_complete'=>count($issues)===0,
        'clone_authorized'=>false,'clone_preview_available'=>false,
        'notice'=>'Read-only mapping inventory. No cloning, timetable validation or saving is performed.'];
}
try {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') ccFail(405,'METHOD_NOT_ALLOWED','GET only.');
    $action=(string)($_GET['action']??'catalog');
    if (!in_array($action,['catalog','assess'],true)) ccFail(400,'INVALID_ACTION','Use action=catalog or action=assess.');
    $db=getDatabase();
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $catalog=ccCatalog($db);
    if ($action==='catalog') {
        ccReply(200,['success'=>true,'status'=>'CLONE_CATALOG_READY']+$catalog+[
            'target_periods_available'=>count($catalog['periods'])>1,'clone_authorized'=>false]);
    }
    $sourceId=ccPositive($_GET['source_batch_id']??null,'source batch');
    $targetId=ccPositive($_GET['target_period_id']??null,'target period');
    ccReply(200,ccAssessment($db,$catalog,$sourceId,$targetId));
} catch (CloneCheckError $e) {
    ccReply($e->httpStatus,['success'=>false,'status'=>$e->statusCode,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('BCP Module 8 clone readiness: '.$e->getMessage());
    ccReply(500,['success'=>false,'status'=>'CLONE_READINESS_ERROR',
        'message'=>'Unable to check source/target readiness. See the Apache/PHP error log. No changes were made.']);
}
