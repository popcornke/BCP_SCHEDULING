
-- ============================================
-- BCP SCHEDULING SYSTEM
-- PHASE 1: CORRECTED SUBJECT IMPORT
--
-- SOURCE: sms_db
-- TARGET: bcp_scheduling
--
-- This migration does not modify the
-- original sms_db database.
-- ============================================


USE bcp_scheduling;


-- ============================================
-- STEP 1: PRESERVE SUBJECT SPECIALIZATION
-- ============================================

-- The old database contains a specialization
-- field for major subjects.
--
-- MariaDB 10.4 supports ADD COLUMN
-- IF NOT EXISTS.

ALTER TABLE subjects
ADD COLUMN IF NOT EXISTS
    specialization VARCHAR(100) NULL
    AFTER subject_code;


-- ============================================
-- STEP 2: START TRANSACTION
-- ============================================

START TRANSACTION;


-- ============================================
-- STEP 3: IMPORT PROGRAMS
-- ============================================

INSERT INTO bcp_scheduling.programs (
    program_code,
    program_name,
    education_level
)

SELECT
    c.course_code,
    c.course_name,
    c.education_level

FROM sms_db.courses AS c

WHERE NOT EXISTS (

    SELECT 1

    FROM bcp_scheduling.programs AS p

    WHERE
        p.program_code COLLATE utf8mb4_unicode_ci
        =
        c.course_code COLLATE utf8mb4_unicode_ci

);


-- ============================================
-- STEP 4: IMPORT SUBJECTS
-- ============================================

INSERT INTO bcp_scheduling.subjects (

    program_id,

    subject_code,
    specialization,
    subject_title,

    units,
    f2f_hours,
    online_hours,

    year_level,
    semester,

    legacy_subject_id,

    is_verified,
    is_active

)

SELECT

    p.program_id,

    s.subject_code,
    s.specialization,
    s.subject_title,

    s.units,
    s.f2f_hrs,
    s.online_hrs,

    yl.year_level_name,

    sem.semester_order,

    s.subject_id,

    0,
    1

FROM sms_db.subjects AS s


-- Match the subject with its original program.

INNER JOIN sms_db.courses AS c

    ON c.id = s.course_id


-- Match the original program with the
-- program in the new database.

INNER JOIN bcp_scheduling.programs AS p

    ON
        p.program_code COLLATE utf8mb4_unicode_ci
        =
        c.course_code COLLATE utf8mb4_unicode_ci


-- Retrieve the correct year level.

INNER JOIN sms_db.year_levels AS yl

    ON
        yl.year_level_id = s.year_level_id
        AND yl.course_id = s.course_id


-- Retrieve the semester.

INNER JOIN sms_db.semesters AS sem

    ON sem.semester_id = s.semester_id


-- Avoid importing the same legacy subject twice.

WHERE NOT EXISTS (

    SELECT 1

    FROM bcp_scheduling.subjects AS existing

    WHERE
        existing.legacy_subject_id = s.subject_id

);


-- ============================================
-- STEP 5: COMMIT IMPORT
-- ============================================

COMMIT;


-- ============================================
-- STEP 6: VERIFY IMPORTED PROGRAMS
-- ============================================

SELECT

    p.program_id,
    p.program_code,
    p.program_name,

    COUNT(s.subject_id) AS total_subjects

FROM bcp_scheduling.programs AS p

LEFT JOIN bcp_scheduling.subjects AS s

    ON s.program_id = p.program_id

GROUP BY

    p.program_id,
    p.program_code,
    p.program_name

ORDER BY p.program_code;


-- ============================================
-- STEP 7: VERIFY IMPORTED BSIT SUBJECTS
-- ============================================

SELECT

    s.subject_id,
    s.legacy_subject_id,

    p.program_code,

    s.subject_code,
    s.subject_title,

    s.specialization,

    s.units,
    s.f2f_hours,
    s.online_hours,

    s.year_level,
    s.semester,

    s.is_verified

FROM bcp_scheduling.subjects AS s

INNER JOIN bcp_scheduling.programs AS p

    ON p.program_id = s.program_id

WHERE p.program_code = 'BSIT'

ORDER BY

    s.year_level,
    s.semester,
    s.subject_code;