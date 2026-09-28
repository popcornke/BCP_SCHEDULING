<?php
declare(strict_types=1);
/** Module 1: LOCAL DEMO ONLY. Read-only individual timetable. No student assignment logic. */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function studentReply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    studentReply(405, ['success'=>false,'status'=>'METHOD_NOT_ALLOWED','message'=>'GET only.']);
}
// This API exposes person-level information. Use the app's authentication and
// student-specific authorization before ever enabling OFFICIAL records or remote access.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    studentReply(403, ['success'=>false,'status'=>'LOCAL_DEMO_ONLY','message'=>'Student DEMO view is only available on localhost.']);
}
$periodId = filter_var($_GET['period_id'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($periodId === false) {
    studentReply(400, ['success'=>false,'status'=>'INVALID_PERIOD','message'=>'Invalid academic period ID.']);
}
$studentNumber = trim((string)($_GET['student_number'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
if (strlen($studentNumber) > 40 || strlen($search) > 60) {
    studentReply(400, ['success'=>false,'status'=>'INVALID_SEARCH','message'=>'Invalid student number or search.']);
}
try {
    $pdo = getDatabase();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $periodStmt = $pdo->prepare("SELECT academic_period_id, academic_year, semester FROM academic_periods WHERE academic_period_id=:pid AND period_status='DEMO' LIMIT 1");
    $periodStmt->execute(['pid'=>$periodId]);
    $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
    if (!$period) studentReply(404, ['success'=>false,'status'=>'DEMO_PERIOD_NOT_FOUND','message'=>'DEMO period not found.']);

    $baseSql = " FROM students st
      JOIN programs p ON p.program_id=st.program_id
      JOIN sections home ON home.section_id=st.home_section_id
      LEFT JOIN sections major ON major.section_id=st.major_section_id
      WHERE st.academic_period_id=:pid AND st.data_origin='DEMO'
        AND p.program_code='BSIT' AND home.program_id=st.program_id
        AND home.academic_period_id=st.academic_period_id AND home.data_origin='DEMO'
        AND (st.major_section_id IS NULL OR
             (major.program_id=st.program_id AND major.academic_period_id=st.academic_period_id
              AND major.data_origin='DEMO'))";

    if ($studentNumber === '') {
        $where = $search === '' ? '' : ' AND (st.student_number LIKE :search_number OR st.first_name LIKE :search_first OR st.last_name LIKE :search_last OR home.section_code LIKE :search_section)';
        $sql = "SELECT st.student_number, st.first_name, st.last_name,
                       home.section_code AS home_section, major.section_code AS major_section"
             . $baseSql . $where . ' ORDER BY st.student_number LIMIT 50';
        $stmt = $pdo->prepare($sql);
        $args = ['pid'=>$periodId];
        if ($search !== '') {
            $args += ['search_number'=>'%'.$search.'%','search_first'=>'%'.$search.'%',
                      'search_last'=>'%'.$search.'%','search_section'=>'%'.$search.'%'];
        }
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = $pdo->prepare('SELECT COUNT(*) FROM students st JOIN programs p ON p.program_id=st.program_id WHERE st.academic_period_id=:pid AND st.data_origin=\'DEMO\' AND p.program_code=\'BSIT\'');
        $count->execute(['pid'=>$periodId]);
        studentReply(200, ['success'=>true,'status'=>'STUDENT_LIST_READY','period'=>$period,
            'total_demo_students'=>(int)$count->fetchColumn(),'shown'=>count($rows),'students'=>$rows,'database_write'=>false]);
    }

    $sql = "SELECT st.student_id, st.student_number, st.first_name, st.last_name,
                   st.home_section_id, st.major_section_id,
                   home.section_code AS home_section, home.section_type AS home_type,
                   major.section_code AS major_section, major.section_type AS major_type,
                   st.program_id, p.program_code, p.program_name" . $baseSql . ' AND st.student_number=:number LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['pid'=>$periodId, 'number'=>$studentNumber]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) studentReply(404, ['success'=>false,'status'=>'STUDENT_NOT_FOUND','message'=>'DEMO student not found for this period.']);
    if (($student['home_type']==='CLUSTER' && ($student['major_section_id']===null || $student['major_type']!=='MAJOR'))
        || ($student['home_type']==='REGULAR' && $student['major_section_id']!==null)) {
        studentReply(409, ['success'=>false,'status'=>'INVALID_STUDENT_MEMBERSHIP','message'=>'Student section membership is incomplete or inconsistent.']);
    }
    if ($student['major_section_id']!==null) {
        $link = $pdo->prepare("SELECT COUNT(*) FROM section_major_links WHERE home_section_id=:home AND major_section_id=:major AND data_origin='DEMO'");
        $link->execute(['home'=>$student['home_section_id'],'major'=>$student['major_section_id']]);
        if ((int)$link->fetchColumn()!==1) {
            studentReply(409, ['success'=>false,'status'=>'INVALID_MAJOR_LINK','message'=>'Student Cluster/Major link does not match the DEMO relationship.']);
        }
    }
    $batchesStmt=$pdo->prepare("SELECT b.batch_id FROM schedule_batches b WHERE b.program_id=:prog AND b.academic_period_id=:pid AND b.status='ACTIVE' AND b.data_origin='DEMO'");
    $batchesStmt->execute(['prog'=>$student['program_id'],'pid'=>$periodId]);
    $batches=$batchesStmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($batches)!==1) {
        studentReply(409, ['success'=>false,'status'=>'NO_UNIQUE_ACTIVE_TIMETABLE','message'=>'A single ACTIVE DEMO program timetable is required.']);
    }
    $batchId=(int)$batches[0];
    $meetingsStmt=$pdo->prepare(<<<'SQL'
SELECT m.meeting_id, sec.section_code, sec.section_type, s.subject_code, s.subject_title,
       t.teacher_name, r.room_name, m.delivery_mode, m.day_of_week,
       TIME_FORMAT(m.start_time,'%H:%i') AS start_time,
       TIME_FORMAT(m.end_time,'%H:%i') AS end_time
FROM schedule_meetings m
JOIN section_subjects ss ON ss.section_subject_id=m.section_subject_id
JOIN sections sec ON sec.section_id=ss.section_id
JOIN subjects s ON s.subject_id=ss.subject_id
JOIN teachers t ON t.teacher_id=m.teacher_id
LEFT JOIN rooms r ON r.room_id=m.room_id
WHERE m.batch_id=:batch AND (sec.section_id=:home OR sec.section_id=:major)
ORDER BY FIELD(m.delivery_mode,'F2F','ONLINE'),
         FIELD(m.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),
         m.start_time, m.meeting_id
SQL);
    $meetingsStmt->execute(['batch'=>$batchId,'home'=>$student['home_section_id'],
                            'major'=>$student['major_section_id'] ?? 0]);
    $meetings=$meetingsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$meetings) studentReply(409, ['success'=>false,'status'=>'STUDENT_TIMETABLE_EMPTY','message'=>'No saved class meetings for the student sections.']);
    $conflicts=[];
    for ($i=0,$n=count($meetings);$i<$n;$i++) {
        for ($j=$i+1;$j<$n;$j++) {
            $a=$meetings[$i]; $b=$meetings[$j];
            if ($a['day_of_week'] === $b['day_of_week'] && $a['start_time']<$b['end_time'] && $b['start_time']<$a['end_time']) {
                $conflicts[]=['day'=>$a['day_of_week'],'first_meeting_id'=>(int)$a['meeting_id'],
                              'second_meeting_id'=>(int)$b['meeting_id'], 'reason'=>'Student has overlapping classes'];
            }
        }
    }
    foreach ($meetings as &$m) $m['meeting_id']=(int)$m['meeting_id'];
    unset($m);
    studentReply(200, ['success'=>true,'status'=>'STUDENT_SCHEDULE_LOADED','period'=>$period,
        'student'=>['student_number'=>$student['student_number'],'full_name'=>trim($student['first_name'].' '.$student['last_name']),
                    'program_code'=>$student['program_code'],'home_section'=>$student['home_section'],
                    'major_section'=>$student['major_section']],
        'batch_id'=>$batchId,'meeting_count'=>count($meetings),'meetings'=>$meetings,
        'student_overlap_check'=>['passed'=>count($conflicts)===0,'conflicts'=>$conflicts],
        'independent_school_wide_audit_rerun'=>false,'database_write'=>false]);
} catch (Throwable $e) {
    error_log('Student timetable DEMO: '.$e->getMessage());
    studentReply(500, ['success'=>false,'status'=>'STUDENT_SCHEDULE_ERROR','message'=>'Unable to load DEMO students or their schedule. Check database schema and PHP error log.']);
}
