
USE bcp_scheduling;

-- ============================================
-- PHASE 2B
-- SAMPLE BSIT SCHEDULING RESOURCES
-- ============================================

-- Do not modify the old sms_db database.


-- ============================================
-- 1. GET EXISTING DEMO PERIOD AND PROGRAM
-- ============================================

SET @program_id = (

    SELECT program_id
    FROM programs
    WHERE program_code = 'BSIT'
    LIMIT 1

);

SET @period_id = (

    SELECT academic_period_id
    FROM academic_periods

    WHERE academic_year = '2026-2027'
      AND semester = 1
      AND period_status = 'DEMO'

    LIMIT 1

);


-- ============================================
-- 2. CREATE 24 SAMPLE TEACHERS
-- ============================================

INSERT INTO teachers (

    program_id,
    employee_no,
    teacher_name,
    demo_no,

    max_daily_hours,
    max_weekly_hours,

    status,
    data_origin

)

SELECT

    @program_id,

    CONCAT(
        'DEMO-BSIT-',
        LPAD(numbers.num, 2, '0')
    ),

    CONCAT(
        'Demo Faculty ',
        LPAD(numbers.num, 2, '0')
    ),

    numbers.num,

    8,
    30,

    'ACTIVE',
    'DEMO'

FROM (

    SELECT
        tens.n * 10 + ones.n + 1 AS num

    FROM (
        SELECT 0 AS n
        UNION ALL SELECT 1
        UNION ALL SELECT 2
    ) AS tens

    CROSS JOIN (

        SELECT 0 AS n
        UNION ALL SELECT 1
        UNION ALL SELECT 2
        UNION ALL SELECT 3
        UNION ALL SELECT 4
        UNION ALL SELECT 5
        UNION ALL SELECT 6
        UNION ALL SELECT 7
        UNION ALL SELECT 8
        UNION ALL SELECT 9

    ) AS ones

) AS numbers

WHERE numbers.num BETWEEN 1 AND 24

AND @program_id IS NOT NULL

AND @period_id IS NOT NULL

AND NOT EXISTS (

    SELECT 1

    FROM teachers AS existing

    WHERE existing.employee_no = CONCAT(
        'DEMO-BSIT-',
        LPAD(numbers.num, 2, '0')
    )

);


-- ============================================
-- 3. AUTHORIZE TEACHERS FOR DEMO SUBJECTS
-- ============================================

-- Each required subject gets three eligible
-- sample teachers.
--
-- These are DEMO qualifications only.
-- They are not verified faculty credentials.

INSERT INTO teacher_subject_authorizations (

    teacher_id,
    subject_id,
    data_origin

)

SELECT DISTINCT

    t.teacher_id,
    s.subject_id,
    'DEMO'

FROM subjects AS s

INNER JOIN section_subjects AS ss
    ON ss.subject_id = s.subject_id

INNER JOIN sections AS sec
    ON sec.section_id = ss.section_id

INNER JOIN teachers AS t
    ON t.program_id = s.program_id

WHERE sec.academic_period_id = @period_id

AND sec.program_id = @program_id

AND sec.data_origin = 'DEMO'

AND s.is_active = 1

AND t.data_origin = 'DEMO'

AND t.status = 'ACTIVE'

AND t.demo_no IN (

    1 + MOD(s.subject_id - 1, 24),

    1 + MOD(s.subject_id, 24),

    1 + MOD(s.subject_id + 1, 24)

)

AND NOT EXISTS (

    SELECT 1

    FROM teacher_subject_authorizations AS existing

    WHERE existing.teacher_id = t.teacher_id

      AND existing.subject_id = s.subject_id

);


-- ============================================
-- 4. TEACHER AVAILABILITY
-- ============================================

-- Sample availability:
-- Monday to Saturday, 06:00 - 21:00.
--
-- These broad availability windows are for
-- development testing only.

INSERT INTO teacher_availability (

    teacher_id,
    academic_period_id,
    day_of_week,

    start_time,
    end_time,

    availability_status,
    data_origin

)

SELECT

    t.teacher_id,

    @period_id,

    days.day_name,

    '06:00:00',
    '21:00:00',

    'AVAILABLE',
    'DEMO'

FROM teachers AS t

CROSS JOIN (

    SELECT 'Monday' AS day_name
    UNION ALL SELECT 'Tuesday'
    UNION ALL SELECT 'Wednesday'
    UNION ALL SELECT 'Thursday'
    UNION ALL SELECT 'Friday'
    UNION ALL SELECT 'Saturday'

) AS days

WHERE t.program_id = @program_id

AND t.data_origin = 'DEMO'

AND t.status = 'ACTIVE'

AND @period_id IS NOT NULL

AND NOT EXISTS (

    SELECT 1

    FROM teacher_availability AS existing

    WHERE existing.teacher_id = t.teacher_id

      AND existing.academic_period_id =
          @period_id

      AND existing.day_of_week = days.day_name

      AND existing.start_time = '06:00:00'

      AND existing.end_time = '21:00:00'

);


-- ============================================
-- 5. CREATE 20 SAMPLE ROOMS
-- ============================================

-- Sample rooms:
-- DEMO-BSIT-201 to DEMO-BSIT-220.
--
-- Capacity: 50 students per room.

INSERT INTO rooms (

    program_id,

    room_name,
    building,

    capacity,
    room_type,

    status,
    data_origin

)

SELECT

    @program_id,

    CONCAT(
        'DEMO-BSIT-',
        200 + numbers.num
    ),

    'DEMO BUILDING',

    50,

    'GENERAL',

    'AVAILABLE',
    'DEMO'

FROM (

    SELECT
        tens.n * 10 + ones.n + 1 AS num

    FROM (

        SELECT 0 AS n
        UNION ALL SELECT 1

    ) AS tens

    CROSS JOIN (

        SELECT 0 AS n
        UNION ALL SELECT 1
        UNION ALL SELECT 2
        UNION ALL SELECT 3
        UNION ALL SELECT 4
        UNION ALL SELECT 5
        UNION ALL SELECT 6
        UNION ALL SELECT 7
        UNION ALL SELECT 8
        UNION ALL SELECT 9

    ) AS ones

) AS numbers

WHERE numbers.num BETWEEN 1 AND 20

AND @program_id IS NOT NULL

AND @period_id IS NOT NULL

AND NOT EXISTS (

    SELECT 1

    FROM rooms AS existing

    WHERE existing.room_name = CONCAT(
        'DEMO-BSIT-',
        200 + numbers.num
    )

);


-- ============================================
-- 6. ROOM AVAILABILITY
-- ============================================

INSERT INTO room_availability (

    room_id,
    academic_period_id,

    day_of_week,

    start_time,
    end_time,

    availability_status,
    data_origin

)

SELECT

    r.room_id,

    @period_id,

    days.day_name,

    '06:00:00',
    '21:00:00',

    'AVAILABLE',
    'DEMO'

FROM rooms AS r

CROSS JOIN (

    SELECT 'Monday' AS day_name
    UNION ALL SELECT 'Tuesday'
    UNION ALL SELECT 'Wednesday'
    UNION ALL SELECT 'Thursday'
    UNION ALL SELECT 'Friday'
    UNION ALL SELECT 'Saturday'

) AS days

WHERE r.program_id = @program_id

AND r.data_origin = 'DEMO'

AND r.status = 'AVAILABLE'

AND @period_id IS NOT NULL

AND NOT EXISTS (

    SELECT 1

    FROM room_availability AS existing

    WHERE existing.room_id = r.room_id

      AND existing.academic_period_id =
          @period_id

      AND existing.day_of_week = days.day_name

      AND existing.start_time = '06:00:00'

      AND existing.end_time = '21:00:00'

);


-- ============================================
-- 7. GENERATE DATABASE TIME SLOTS
-- ============================================

-- Six days, Monday to Saturday.
--
-- Each day:
-- 06:00 - 21:00
--
-- Slot duration:
-- 30 minutes.
--
-- 30 slots per day x 6 days = 180 slots.
--
-- F2F: Monday, Wednesday, Friday.
-- Online: Tuesday, Thursday, Saturday.

INSERT INTO time_slots (

    day_of_week,
    day_pattern,

    start_time,
    end_time,

    is_active,
    data_origin

)

SELECT

    days.day_name,

    days.day_pattern,

    SEC_TO_TIME(
        21600 + numbers.slot_no * 1800
    ),

    SEC_TO_TIME(
        21600 + (numbers.slot_no + 1) * 1800
    ),

    1,
    'DEMO'

FROM (

    SELECT
        'Monday' AS day_name,
        'MWF' AS day_pattern

    UNION ALL SELECT 'Tuesday', 'TTHS'

    UNION ALL SELECT 'Wednesday', 'MWF'

    UNION ALL SELECT 'Thursday', 'TTHS'

    UNION ALL SELECT 'Friday', 'MWF'

    UNION ALL SELECT 'Saturday', 'TTHS'

) AS days

CROSS JOIN (

    SELECT
        tens.n * 10 + ones.n AS slot_no

    FROM (

        SELECT 0 AS n
        UNION ALL SELECT 1
        UNION ALL SELECT 2

    ) AS tens

    CROSS JOIN (

        SELECT 0 AS n
        UNION ALL SELECT 1
        UNION ALL SELECT 2
        UNION ALL SELECT 3
        UNION ALL SELECT 4
        UNION ALL SELECT 5
        UNION ALL SELECT 6
        UNION ALL SELECT 7
        UNION ALL SELECT 8
        UNION ALL SELECT 9

    ) AS ones

) AS numbers

WHERE numbers.slot_no BETWEEN 0 AND 29

AND @period_id IS NOT NULL

AND NOT EXISTS (

    SELECT 1

    FROM time_slots AS existing

    WHERE existing.day_of_week = days.day_name

      AND existing.start_time = SEC_TO_TIME(
          21600 + numbers.slot_no * 1800
      )

      AND existing.end_time = SEC_TO_TIME(
          21600 + (numbers.slot_no + 1) * 1800
      )

      AND existing.data_origin = 'DEMO'

);


-- ============================================
-- 8. FINAL RESOURCE COUNTS
-- ============================================

SELECT

    (
        SELECT COUNT(*)
        FROM teachers
        WHERE program_id = @program_id
          AND data_origin = 'DEMO'
    ) AS total_teachers,

    (
        SELECT COUNT(*)
        FROM rooms
        WHERE program_id = @program_id
          AND data_origin = 'DEMO'
    ) AS total_rooms,

    (
        SELECT COUNT(*)
        FROM time_slots
        WHERE data_origin = 'DEMO'
          AND is_active = 1
    ) AS total_time_slots,

    (
        SELECT COUNT(*)
        FROM teacher_subject_authorizations
        WHERE data_origin = 'DEMO'
    ) AS total_authorizations,

    (
        SELECT COUNT(*)
        FROM teacher_availability
        WHERE academic_period_id = @period_id
          AND data_origin = 'DEMO'
    ) AS total_teacher_availability,

    (
        SELECT COUNT(*)
        FROM room_availability
        WHERE academic_period_id = @period_id
          AND data_origin = 'DEMO'
    ) AS total_room_availability;