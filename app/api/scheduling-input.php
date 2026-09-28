<?php
declare(strict_types=1);

/**
 * BCP Automatic Class Scheduling System
 *
 * Phase 3A:
 * Scheduling Input Loader
 *
 * This endpoint reads the existing DEMO data.
 * It does not generate or save schedules.
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fetchRows(
    PDO $pdo,
    string $sql,
    array $params = []
): array {

    $statement = $pdo->prepare($sql);

    $statement->execute($params);

    return $statement->fetchAll();
}

try {

    $pdo = getDatabase();

    // ========================================
    // 1. REQUEST PARAMETERS
    // ========================================

    $programCode = strtoupper(trim(
        (string) ($_GET['program'] ?? 'BSIT')
    ));

    $academicYear = trim(
        (string) ($_GET['academic_year'] ?? '2026-2027')
    );

    $semester = filter_var(
        $_GET['semester'] ?? 1,
        FILTER_VALIDATE_INT
    );

    if (
        !preg_match('/^[A-Z0-9]{2,30}$/', $programCode)
        || !preg_match('/^[0-9]{4}-[0-9]{4}$/', $academicYear)
        || !in_array($semester, [1, 2], true)
    ) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid scheduling parameters.'
        ]);

        exit;
    }


    // ========================================
    // 2. LOAD PROGRAM
    // ========================================

    $programStatement = $pdo->prepare("

        SELECT
            program_id,
            program_code,
            program_name

        FROM programs

        WHERE program_code = :program_code
          AND is_active = 1

        LIMIT 1

    ");

    $programStatement->execute([
        'program_code' => $programCode
    ]);

    $program = $programStatement->fetch();

    if (!$program) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Program not found.'
        ]);

        exit;
    }


    // ========================================
    // 3. LOAD ACADEMIC PERIOD
    // ========================================

    $periodStatement = $pdo->prepare("

        SELECT
            academic_period_id,
            academic_year,
            semester,
            period_status

        FROM academic_periods

        WHERE academic_year = :academic_year
          AND semester = :semester

        LIMIT 1

    ");

    $periodStatement->execute([
        'academic_year' => $academicYear,
        'semester' => $semester
    ]);

    $period = $periodStatement->fetch();

    if (!$period) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Academic period not found.'
        ]);

        exit;
    }

    // Phase 3A currently supports demo inputs only.

    if ($period['period_status'] !== 'DEMO') {

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'This loader currently supports DEMO periods only.'
        ]);

        exit;
    }


    $programId = (int) $program['program_id'];

    $periodId = (int) $period['academic_period_id'];


    // ========================================
    // 4. LOAD SECTIONS
    // ========================================

    $sections = fetchRows($pdo, "

        SELECT

            section_id,
            section_code,
            year_level,
            section_type,
            student_count,
            program_id,
            academic_period_id

        FROM sections

        WHERE program_id = :program_id
          AND academic_period_id = :period_id
          AND data_origin = 'DEMO'
          AND is_active = 1

        ORDER BY
            year_level,
            section_code

    ", [
        'program_id' => $programId,
        'period_id' => $periodId
    ]);


    // ========================================
    // 5. LOAD REQUIRED SECTION SUBJECTS
    // ========================================

    $sectionSubjects = fetchRows($pdo, "

        SELECT

            ss.section_subject_id,

            ss.section_id,

            s.subject_id,
            s.subject_code,
            s.subject_title,

            s.specialization,

            s.units,
            s.f2f_hours,
            s.online_hours,

            s.year_level,
            s.semester,

            s.is_verified

        FROM section_subjects AS ss

        INNER JOIN sections AS sec
            ON sec.section_id = ss.section_id

        INNER JOIN subjects AS s
            ON s.subject_id = ss.subject_id

        WHERE sec.program_id = :program_id

          AND sec.academic_period_id = :period_id

          AND sec.data_origin = 'DEMO'

          AND sec.is_active = 1

          AND s.is_active = 1

          AND s.program_id = sec.program_id

          AND s.semester = :semester

        ORDER BY
            ss.section_id,
            s.subject_code

    ", [
        'program_id' => $programId,
        'period_id' => $periodId,
        'semester' => $semester
    ]);


    // ========================================
    // 6. LOAD ACTIVE TEACHERS
    // ========================================

    $teachers = fetchRows($pdo, "

        SELECT

            teacher_id,
            employee_no,
            teacher_name,

            program_id,

            max_daily_hours,
            max_weekly_hours

        FROM teachers

        WHERE data_origin = 'DEMO'
          AND status = 'ACTIVE'
          AND program_id = :program_id

        ORDER BY teacher_id

    ", [
        'program_id' => $programId
    ]);


    // ========================================
    // 7. LOAD SUBJECT AUTHORIZATIONS
    // ========================================

    $authorizations = fetchRows($pdo, "

        SELECT

            a.authorization_id,
            a.teacher_id,
            a.subject_id

        FROM teacher_subject_authorizations AS a

        INNER JOIN teachers AS t
            ON t.teacher_id = a.teacher_id

        INNER JOIN subjects AS s
            ON s.subject_id = a.subject_id

        WHERE a.data_origin = 'DEMO'
          AND t.data_origin = 'DEMO'
          AND t.status = 'ACTIVE'
          AND t.program_id = :program_id
          AND s.program_id = :subject_program_id

    ", [
        'program_id' => $programId,
        'subject_program_id' => $programId
    ]);


    // ========================================
    // 8. LOAD TEACHER AVAILABILITY
    // ========================================

    $teacherAvailability = fetchRows($pdo, "

        SELECT

            ta.teacher_id,
            ta.academic_period_id,

            ta.day_of_week,
            ta.start_time,
            ta.end_time,

            ta.availability_status

        FROM teacher_availability AS ta

        INNER JOIN teachers AS t
            ON t.teacher_id = ta.teacher_id

        WHERE ta.academic_period_id = :period_id

          AND ta.data_origin = 'DEMO'

          AND t.data_origin = 'DEMO'

          AND t.status = 'ACTIVE'
          AND t.program_id = :program_id

        ORDER BY
            ta.teacher_id,
            ta.day_of_week,
            ta.start_time

    ", [
        'period_id' => $periodId,
        'program_id' => $programId
    ]);


    // ========================================
    // 9. LOAD ROOMS
    // ========================================

    $rooms = fetchRows($pdo, "

        SELECT

            room_id,
            room_name,
            building,

            program_id,
            capacity,
            room_type

        FROM rooms

        WHERE data_origin = 'DEMO'

          AND status = 'AVAILABLE'

        ORDER BY
            program_id,
            room_name

    ");


    // ========================================
    // 10. LOAD ROOM AVAILABILITY
    // ========================================

    $roomAvailability = fetchRows($pdo, "

        SELECT

            ra.room_id,
            ra.academic_period_id,

            ra.day_of_week,
            ra.start_time,
            ra.end_time,

            ra.availability_status

        FROM room_availability AS ra

        INNER JOIN rooms AS r
            ON r.room_id = ra.room_id

        WHERE ra.academic_period_id = :period_id

          AND ra.data_origin = 'DEMO'

          AND r.data_origin = 'DEMO'

          AND r.status = 'AVAILABLE'

        ORDER BY
            ra.room_id,
            ra.day_of_week,
            ra.start_time

    ", [
        'period_id' => $periodId
    ]);


    // ========================================
    // 11. LOAD DATABASE TIME SLOTS
    // ========================================

    $timeSlots = fetchRows($pdo, "

        SELECT

            time_slot_id,

            day_of_week,
            day_pattern,

            start_time,
            end_time

        FROM time_slots

        WHERE data_origin = 'DEMO'
          AND is_active = 1

        ORDER BY

            FIELD(
                day_of_week,
                'Monday',
                'Tuesday',
                'Wednesday',
                'Thursday',
                'Friday',
                'Saturday'
            ),

            start_time

    ");


    // ========================================
    // 12. LOAD CLUSTER / MAJOR RELATIONSHIPS
    // ========================================

    $majorLinks = fetchRows($pdo, "

        SELECT

            l.link_id,

            l.home_section_id,
            l.major_section_id,

            l.linked_student_count

        FROM section_major_links AS l

        INNER JOIN sections AS home
            ON home.section_id = l.home_section_id

        INNER JOIN sections AS major
            ON major.section_id = l.major_section_id

        WHERE home.program_id = :program_id

          AND home.academic_period_id = :period_id

          AND major.program_id = home.program_id

          AND major.academic_period_id =
              home.academic_period_id

          AND home.section_type = 'CLUSTER'

          AND major.section_type = 'MAJOR'

          AND home.data_origin = 'DEMO'

          AND major.data_origin = 'DEMO'

          AND home.is_active = 1

          AND major.is_active = 1

          AND l.data_origin = 'DEMO'

        ORDER BY l.link_id

    ", [
        'program_id' => $programId,
        'period_id' => $periodId
    ]);


    // ========================================
    // PHASE 4B — EXISTING ACTIVE TIMETABLE SNAPSHOT
    // Read only; school-wide, same academic period.
    // ========================================

    $activeBatches = fetchRows($pdo, "
        SELECT batch_id, program_id, data_origin
        FROM schedule_batches
        WHERE academic_period_id = :period_id AND status = 'ACTIVE'
        ORDER BY batch_id
    ", ['period_id' => $periodId]);

    $existingMeetings = fetchRows($pdo, "
        SELECT
            m.meeting_id, m.batch_id, b.academic_period_id,
            b.program_id, b.data_origin,
            ss.section_subject_id, sec.section_id,
            sec.program_id AS section_program_id,
            sec.academic_period_id AS section_academic_period_id,
            sec.student_count AS section_student_count,
            subj.subject_id, subj.program_id AS subject_program_id,
            m.teacher_id,
            t.program_id AS teacher_program_id,
            m.room_id, r.program_id AS room_program_id,
            r.capacity AS room_capacity,
            m.delivery_mode, m.day_of_week,
            m.start_time, m.end_time
        FROM schedule_meetings AS m
        INNER JOIN schedule_batches AS b ON b.batch_id = m.batch_id
        INNER JOIN section_subjects AS ss ON ss.section_subject_id = m.section_subject_id
        INNER JOIN sections AS sec ON sec.section_id = ss.section_id
        INNER JOIN subjects AS subj ON subj.subject_id = ss.subject_id
        INNER JOIN teachers AS t ON t.teacher_id = m.teacher_id
        LEFT JOIN rooms AS r ON r.room_id = m.room_id
        WHERE b.academic_period_id = :period_id AND b.status = 'ACTIVE'
        ORDER BY m.meeting_id
    ", ['period_id' => $periodId]);

    $savedCountsByBatch = [];
    $activePrograms = [];
    foreach ($activeBatches as $batch) {
        $batchId = (int) $batch['batch_id'];
        $savedCountsByBatch[$batchId] = 0;
        $owner = (int) $batch['program_id'];
        $activePrograms[$owner] = ($activePrograms[$owner] ?? 0) + 1;
    }
    foreach ($existingMeetings as $meeting) {
        $batchId = (int) $meeting['batch_id'];
        if (isset($savedCountsByBatch[$batchId])) {
            $savedCountsByBatch[$batchId]++;
        }
    }

    // ========================================
    // 13. BASIC INPUT VALIDATION
    // ========================================

    $errors = [];
    // Never silently overwrite or mix active schedules for the selected program.
    if (isset($activePrograms[$programId])) {
        $errors[] = 'This program already has an ACTIVE timetable. '
            . 'Explicit regeneration/replacement workflow is not implemented yet.';
    }
    foreach ($activePrograms as $owner => $count) {
        if ($count !== 1) {
            $errors[] = "Program {$owner} has multiple ACTIVE schedule batches.";
        }
    }
    foreach ($savedCountsByBatch as $batchId => $count) {
        if ($count === 0) {
            $errors[] = "ACTIVE schedule batch {$batchId} has no meetings.";
        }
    }
    foreach ($activeBatches as $batch) {
        if ($batch['data_origin'] !== 'DEMO') {
            $errors[] = 'An ACTIVE saved batch has a different data origin.';
        }
    }



    if (count($sections) === 0) {
        $errors[] = 'No active sample sections found.';
    }

    if (count($sectionSubjects) === 0) {
        $errors[] = 'No required section subjects found.';
    }

    if (count($teachers) === 0) {
        $errors[] = 'No active sample teachers found.';
    }

    if (count($rooms) === 0) {
        $errors[] = 'No available sample rooms found.';
    }

    if (count($timeSlots) === 0) {
        $errors[] = 'No active database time slots found.';
    }

    // Check authorization coverage for every
    // required subject.

    $authorizedSubjectIds = [];

    foreach ($authorizations as $authorization) {

        $authorizedSubjectIds[
            (int) $authorization['subject_id']
        ] = true;
    }

    foreach ($sectionSubjects as $assignment) {

        $subjectId = (int) $assignment['subject_id'];

        if (!isset($authorizedSubjectIds[$subjectId])) {

            $errors[] = sprintf(
                'No authorized teacher for subject %s in section %s.',
                $assignment['subject_code'],
                $assignment['section_id']
            );
        }
    }

    $errors = array_values(array_unique($errors));


    // ========================================
    // 14. RETURN SCHEDULING INPUT
    // ========================================

    $ready = count($errors) === 0;

    echo json_encode(

        [

            'success' => $ready,

            'status' => $ready
                ? 'BASIC_INPUT_READY'
                : 'INPUT_VALIDATION_FAILED',

            'solver_ready' => false,

            'data_origin' => 'DEMO',

            'program' => $program,

            'academic_period' => $period,

            'counts' => [

                'sections' => count($sections),

                'section_subjects' =>
                    count($sectionSubjects),

                'teachers' => count($teachers),

                'authorizations' =>
                    count($authorizations),

                'teacher_availability' =>
                    count($teacherAvailability),

                'rooms' => count($rooms),

                'room_availability' =>
                    count($roomAvailability),

                'time_slots' => count($timeSlots),

                'major_links' => count($majorLinks),
                'existing_meetings' => count($existingMeetings)

            ],

            'validation_errors' => $errors,

            'scheduling_input' => [

                'sections' => $sections,

                'section_subjects' => $sectionSubjects,

                'teachers' => $teachers,

                'authorizations' => $authorizations,

                'teacher_availability' =>
                    $teacherAvailability,

                'rooms' => $rooms,

                'room_availability' =>
                    $roomAvailability,

                'time_slots' => $timeSlots,

                'major_links' => $majorLinks,
                'existing_meetings' => $existingMeetings

            ]

        ],

        JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE

    );

} catch (Throwable $exception) {

    error_log($exception->getMessage());

    http_response_code(500);

    echo json_encode([

        'success' => false,

        'status' => 'INPUT_LOADER_FAILED',

        'message' =>
            'Failed to prepare scheduling input.'

    ]);
}