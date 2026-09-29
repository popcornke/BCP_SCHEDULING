<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';
authRequire(true);
/** Module 6 / Phase 6B. DEMO-only, localhost-only, read-only request preparation. No DB writes. */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/special-class-calendar.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

final class ScPreparationException extends RuntimeException {
    public function __construct(public int $httpCode, public string $statusCode, string $message) {
        parent::__construct($message);
    }
}
function prepFail(int $http, string $status, string $message): never {
    throw new ScPreparationException($http, $status, $message);
}
function prepReply(int $http, array $body): never {
    http_response_code($http);
    echo json_encode($body + ['database_write' => false, 'saving_enabled' => false],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}
function prepRows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function prepId(mixed $value, string $field): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($id === false) prepFail(400, 'INVALID_SELECTION', 'Select a valid ' . $field . '.');
    return (int)$id;
}
function prepContext(PDO $pdo, int $periodId, int $programId): array {
    $period = prepRows($pdo, "SELECT academic_period_id,academic_year,semester FROM academic_periods
        WHERE academic_period_id=:period AND period_status='DEMO' LIMIT 1", ['period'=>$periodId]);
    $program = prepRows($pdo, "SELECT program_id,program_code,program_name FROM programs
        WHERE program_id=:program AND is_active=1 LIMIT 1", ['program'=>$programId]);
    if (!$period || !$program) prepFail(404, 'DEMO_CONTEXT_NOT_FOUND', 'Select a DEMO academic period and an active program.');
    return ['period'=>$period[0], 'program'=>$program[0]];
}
function prepSubject(PDO $pdo, int $subjectId, int $programId, int $semester): array {
    $subject = prepRows($pdo, "SELECT subject_id,subject_code,subject_title FROM subjects
        WHERE subject_id=:subject AND program_id=:program AND semester=:semester AND is_active=1 LIMIT 1",
        ['subject'=>$subjectId,'program'=>$programId,'semester'=>$semester]);
    if (!$subject) prepFail(422,'SUBJECT_NOT_AVAILABLE','Selected subject is not active for this program and semester.');
    return $subject[0];
}
function prepFaculty(PDO $pdo, int $subjectId, int $programId): array {
    return prepRows($pdo, "SELECT t.teacher_id,t.teacher_name,t.employee_no,t.max_daily_hours,t.max_weekly_hours
        FROM teachers t JOIN teacher_subject_authorizations a ON a.teacher_id=t.teacher_id
        WHERE t.program_id=:program AND t.status='ACTIVE' AND t.data_origin='DEMO'
          AND a.subject_id=:subject AND a.data_origin='DEMO'
        ORDER BY t.teacher_name,t.teacher_id", ['program'=>$programId,'subject'=>$subjectId]);
}
function prepStudentList(PDO $pdo, int $periodId, int $programId, string $query, int $page): array {
    $params=['period'=>$periodId,'program'=>$programId];
    $where=" FROM students st
        JOIN sections home ON home.section_id=st.home_section_id
        LEFT JOIN sections major ON major.section_id=st.major_section_id
        WHERE st.academic_period_id=:period AND st.program_id=:program AND st.data_origin='DEMO'
        AND home.academic_period_id=st.academic_period_id AND home.program_id=st.program_id
        AND home.data_origin='DEMO' AND home.is_active=1
        AND (st.major_section_id IS NULL OR (major.academic_period_id=st.academic_period_id
             AND major.program_id=st.program_id AND major.data_origin='DEMO' AND major.is_active=1))";
    if ($query !== '') {
        $where .= " AND (st.student_number LIKE :number OR st.first_name LIKE :first
                        OR st.last_name LIKE :last OR home.section_code LIKE :home
                        OR major.section_code LIKE :major)";
        $q='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$query).'%';
        foreach (['number','first','last','home','major'] as $key) $params[$key]=$q;
    }
    $count = prepRows($pdo, 'SELECT COUNT(*) AS total'.$where, $params);
    $total=(int)$count[0]['total'];
    $offset=($page-1)*50;
    $stmt=$pdo->prepare("SELECT st.student_id,st.student_number,st.first_name,st.last_name,
        st.home_section_id,st.major_section_id,home.section_code AS home_section,
        major.section_code AS major_section".$where." ORDER BY st.student_number LIMIT 50 OFFSET ".$offset);
    $stmt->execute($params);
    return ['students'=>$stmt->fetchAll(PDO::FETCH_ASSOC), 'total'=>$total, 'page'=>$page,
        'page_size'=>50, 'total_pages'=>(int)ceil($total/50)];
}
function prepIsoDate(mixed $value, string $field): DateTimeImmutable {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) {
        prepFail(400,'INVALID_DATE','Choose a valid '.$field.' (YYYY-MM-DD).');
    }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value, new DateTimeZone('Asia/Manila'));
    if (!$date || $date->format('Y-m-d')!==$value) prepFail(400,'INVALID_DATE','Invalid '.$field.'.');
    return $date;
}
function prepWeeklyDates(array $data): array {
    $first=prepIsoDate($data['start_date']??null,'weekly start date');
    $last=prepIsoDate($data['end_date']??null,'weekly end date');
    // Phase 6B demo input limit: max 16 weeks; not a BCP policy on class length.
    if ($last<$first || $first->diff($last)->days > 111) {
        prepFail(422,'INVALID_DATE_RANGE','Choose dates in order, within a maximum 16-week DEMO preparation window.');
    }
    $requested=$data['weekdays']??null;
    $days=['Monday'=>1,'Tuesday'=>2,'Wednesday'=>3,'Thursday'=>4,'Friday'=>5,'Saturday'=>6];
    if (!is_array($requested) || !$requested || count($requested)>6) {
        prepFail(422,'INVALID_WEEKDAYS','Choose one or more Monday–Saturday weekdays.');
    }
    $selected=[];
    foreach ($requested as $name) {
        if (!is_string($name) || !isset($days[$name]) || isset($selected[$name])) {
            prepFail(422,'INVALID_WEEKDAYS','Choose distinct Monday–Saturday weekdays.');
        }
        $selected[$name]=true;
    }
    $occurrences=[];
    for ($d=$first; $d<=$last; $d=$d->modify('+1 day')) {
        if (isset($selected[$d->format('l')])) $occurrences[]=['date'=>$d->format('Y-m-d'),'day_of_week'=>$d->format('l')];
    }
    if (!$occurrences) prepFail(422,'NO_WEEKLY_DATES','No selected weekday falls within the chosen date range.');
    return $occurrences;
}
function prepParticipants(PDO $pdo, int $periodId, int $programId, array $requested): array {
    if (!$requested || count($requested)>50) prepFail(422,'INVALID_PARTICIPANTS','Select between 1 and 50 actual DEMO students.');
    $ids=[];
    foreach ($requested as $value) {
        $id=prepId($value,'student');
        if (isset($ids[$id])) prepFail(422,'DUPLICATE_STUDENT','A student was selected more than once.');
        $ids[$id]=true;
    }
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT st.student_id,st.student_number,st.first_name,st.last_name,
        st.home_section_id,st.major_section_id,home.section_code AS home_section,
        major.section_code AS major_section
        FROM students st JOIN sections home ON home.section_id=st.home_section_id
        LEFT JOIN sections major ON major.section_id=st.major_section_id
        WHERE st.academic_period_id=? AND st.program_id=? AND st.data_origin='DEMO'
        AND home.academic_period_id=st.academic_period_id AND home.program_id=st.program_id
        AND home.data_origin='DEMO' AND home.is_active=1
        AND (st.major_section_id IS NULL OR (major.academic_period_id=st.academic_period_id
        AND major.program_id=st.program_id AND major.data_origin='DEMO' AND major.is_active=1))
        AND st.student_id IN ($placeholders) ORDER BY st.student_number";
    $rows=prepRows($pdo,$sql,array_merge([$periodId,$programId],array_keys($ids)));
    if (count($rows)!==count($ids)) prepFail(409,'STUDENT_MEMBERSHIP_CHANGED',
        'Some selected students no longer have valid current DEMO section memberships. Refresh the roster.');
    return $rows;
}
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','POST'],true)) prepFail(405,'METHOD_NOT_ALLOWED','GET/POST only.');
    $data=$method==='POST'?json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR):$_GET;
    if (!is_array($data)) prepFail(400,'INVALID_REQUEST','Invalid request body.');
    $periodId=prepId($data['period_id']??null,'academic period');
    $programId=prepId($data['program_id']??null,'program');
    $pdo=getDatabase(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $context=prepContext($pdo,$periodId,$programId);
    if ($method==='GET') {
        $action=(string)($data['action']??'');
        if ($action==='students') {
            $q=trim((string)($data['search']??''));
            if (strlen($q)>60) prepFail(400,'INVALID_SEARCH','Search must be at most 60 characters.');
            $page=prepId($data['page']??1,'page');
            if ($page>1000) prepFail(400,'INVALID_PAGE','Page is out of range.');
            prepReply(200,['success'=>true,'status'=>'SPECIAL_CLASS_STUDENTS_READY','period'=>$context['period'],
                'program'=>$context['program']] + prepStudentList($pdo,$periodId,$programId,$q,$page));
        }
        if ($action==='faculty') {
            $subjectId=prepId($data['subject_id']??null,'subject');
            $subject=prepSubject($pdo,$subjectId,$programId,(int)$context['period']['semester']);
            prepReply(200,['success'=>true,'status'=>'SPECIAL_CLASS_AUTHORIZED_FACULTY_READY',
                'subject'=>$subject,'faculty'=>prepFaculty($pdo,$subjectId,$programId),
                'notice'=>'Subject authorization only. Date/time availability and conflicts are NOT yet checked.']);
        }
        prepFail(400,'INVALID_ACTION','Use action=students or action=faculty.');
    }
    if (($data['action']??'')!=='prepare') prepFail(400,'INVALID_ACTION','Use the prepare action.');
    $type=$data['class_type']??null;
    if (!in_array($type,['REMEDIAL','IRREGULAR','OCTOBERIAN'],true)) {
        prepFail(422,'INVALID_CLASS_TYPE','Select Remedial, Irregular, or Octoberian.');
    }
    $subjectId=prepId($data['subject_id']??null,'subject');
    $teacherId=prepId($data['teacher_id']??null,'professor');
    $subject=prepSubject($pdo,$subjectId,$programId,(int)$context['period']['semester']);
    $faculty=prepFaculty($pdo,$subjectId,$programId);
    $teacher=null;
    foreach ($faculty as $row) if ((int)$row['teacher_id']===$teacherId) { $teacher=$row; break; }
    if ($teacher===null) prepFail(422,'FACULTY_NOT_AUTHORIZED',
        'The selected professor is not an active authorized DEMO teacher for this subject and program.');
    if (!is_array($data['student_ids']??null)) prepFail(422,'INVALID_PARTICIPANTS','Choose actual students.');
    $students=prepParticipants($pdo,$periodId,$programId,$data['student_ids']);
    $dates=prepWeeklyDates($data);
    $calendar=scCalendarRecord($pdo,$periodId);
    scCalendarAssertRange($calendar,$data['start_date']??null,$data['end_date']??null,$dates);
    // This endpoint deliberately returns a request preparation, not feasible times, room choices or a schedulable preview.
    // All date/time, student, teacher, room and exam conflict checks must occur AFTER the school approves duration.
    prepReply(200,['success'=>true,'status'=>'SPECIAL_CLASS_REQUEST_PREPARED',
        'phase'=>'UNSAVED_REQUEST_PREPARATION','period'=>$context['period'],'program'=>$context['program'],
        'class_type'=>$type,'recurrence'=>'WEEKLY','subject'=>$subject,'teacher'=>$teacher,
        'participants'=>$students,'participant_count'=>count($students),
        'weekly_occurrences'=>$dates,'occurrence_count'=>count($dates),
        'academic_calendar'=>scCalendarPublic($calendar),
        'duration_policy'=>'PENDING_APPROVAL','octoberian_policy'=>'GENERIC_ONLY',
        'schedule_preview_available'=>false,'room_assigned'=>false,'teacher_time_availability_checked'=>false,
        'student_conflicts_checked'=>false,'exam_conflicts_checked'=>false,
        'notice'=>'Prepared only. No duration, start/end time, room or class meeting has been generated or saved.']);
} catch (ScCalendarViolation $e) {
    prepReply($e->httpStatus,['success'=>false,'status'=>$e->statusCode,'message'=>$e->getMessage()]);
} catch (ScPreparationException $e) {
    prepReply($e->httpCode,['success'=>false,'status'=>$e->statusCode,'message'=>$e->getMessage()]);
} catch (JsonException $e) {
    prepReply(400,['success'=>false,'status'=>'INVALID_JSON','message'=>'Request JSON could not be parsed.']);
} catch (Throwable $e) {
    error_log('BCP Module 6 Phase 6B: '.$e->getMessage());
    prepReply(500,['success'=>false,'status'=>'SPECIAL_CLASS_PREPARATION_ERROR',
        'message'=>'Unable to prepare the special-class request. Check the PHP error log.']);
}
