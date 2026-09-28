
USE bcp_scheduling;

START TRANSACTION;

-- =============================================
-- 1. ACADEMIC PERIOD
-- =============================================

INSERT INTO academic_periods (
    academic_year,
    semester,
    period_status
)
VALUES (
    '2026-2027',
    1,
    'DEMO'
)
ON DUPLICATE KEY UPDATE
    academic_period_id = academic_period_id;


-- =============================================
-- 2. GET EXISTING PROGRAM AND PERIOD
-- =============================================

SET @period_id = (
    SELECT academic_period_id
    FROM academic_periods
    WHERE academic_year = '2026-2027'
      AND semester = 1
      AND period_status = 'DEMO'
);

SET @program_id = (
    SELECT program_id
    FROM programs
    WHERE program_code = 'BSIT'
);


-- =============================================
-- 3. SAMPLE SECTIONS
-- =============================================

INSERT INTO sections (

    academic_period_id,
    program_id,
    section_code,
    year_level,
    section_type,
    student_count,
    data_origin

)

SELECT

    @period_id,
    @program_id,

    sample.section_code,
    sample.year_level,
    sample.section_type,

    50,
    'DEMO'

FROM (

    SELECT
        '11001' AS section_code,
        1 AS year_level,
        'REGULAR' AS section_type

    UNION ALL SELECT '11002', 1, 'REGULAR'

    UNION ALL SELECT '21001', 2, 'REGULAR'
    UNION ALL SELECT '21002', 2, 'REGULAR'

    UNION ALL SELECT '31001', 3, 'REGULAR'
    UNION ALL SELECT '31002', 3, 'REGULAR'
    UNION ALL SELECT '31003', 3, 'REGULAR'

    UNION ALL SELECT '41001', 4, 'CLUSTER'
    UNION ALL SELECT '41002', 4, 'CLUSTER'
    UNION ALL SELECT '41003', 4, 'CLUSTER'

    UNION ALL SELECT '41004', 4, 'MAJOR'
    UNION ALL SELECT '41005', 4, 'MAJOR'
    UNION ALL SELECT '41006', 4, 'MAJOR'

) AS sample

WHERE NOT EXISTS (

    SELECT 1

    FROM sections AS existing

    WHERE existing.academic_period_id = @period_id
      AND existing.program_id = @program_id
      AND existing.section_code = sample.section_code

);


-- =============================================
-- 4. SECTION SUBJECT ASSIGNMENTS
-- =============================================

INSERT INTO section_subjects (

    section_id,
    subject_id,
    data_origin

)

SELECT

    sec.section_id,
    sub.subject_id,
    'DEMO'

FROM sections AS sec

INNER JOIN subjects AS sub
    ON sub.program_id = sec.program_id

WHERE sec.academic_period_id = @period_id
  AND sec.program_id = @program_id
  AND sec.data_origin = 'DEMO'
  AND sub.semester = 1
  AND sub.is_active = 1

  AND (

    -- FIRST YEAR:
    -- All 9 first-semester subjects.

    (
        sec.section_code IN ('11001', '11002')
        AND sub.year_level = '1st Year'
    )

    OR

    -- SECOND YEAR:
    -- All 8 first-semester subjects.

    (
        sec.section_code IN ('21001', '21002')
        AND sub.year_level = '2nd Year'
    )

    OR

    -- THIRD YEAR:
    -- Five common subjects plus one specialization.

    (
        sec.section_code IN (
            '31001',
            '31002',
            '31003'
        )

        AND sub.subject_code IN (
            'CC106',
            'IAS101',
            'IM101',
            'ITE3',
            'PM101'
        )
    )

    OR

    (
        sec.section_code = '31001'
        AND sub.subject_code = 'ITSP1A'
    )

    OR

    (
        sec.section_code = '31002'
        AND sub.subject_code = 'ITSP1B'
    )

    OR

    (
        sec.section_code = '31003'
        AND sub.subject_code = 'ITSP1C'
    )

    OR

    -- FOURTH-YEAR CLUSTER:
    -- Three cluster subjects.

    (
        sec.section_code IN (
            '41001',
            '41002',
            '41003'
        )

        AND sub.subject_code IN (
            'ITE4',
            'CAP101',
            'PRAC101'
        )
    )

    OR

    -- FOURTH-YEAR MAJOR:
    -- One subject per major section.

    (
        sec.section_code = '41004'
        AND sub.subject_code = 'ITSP3A'
    )

    OR

    (
        sec.section_code = '41005'
        AND sub.subject_code = 'ITSP3B'
    )

    OR

    (
        sec.section_code = '41006'
        AND sub.subject_code = 'ITSP3C'
    )

  )

AND NOT EXISTS (

    SELECT 1

    FROM section_subjects AS existing

    WHERE existing.section_id = sec.section_id
      AND existing.subject_id = sub.subject_id

);


-- =============================================
-- 5. FOURTH-YEAR CLUSTER / MAJOR LINKS
-- =============================================

INSERT INTO section_major_links (

    home_section_id,
    major_section_id,
    linked_student_count,
    data_origin

)

SELECT

    home.section_id,
    major.section_id,

    50,
    'DEMO'

FROM sections AS home

INNER JOIN sections AS major

    ON major.academic_period_id =
       home.academic_period_id

    AND major.program_id = home.program_id

WHERE home.academic_period_id = @period_id

  AND home.program_id = @program_id

  AND home.data_origin = 'DEMO'

  AND major.data_origin = 'DEMO'

  AND home.section_type = 'CLUSTER'

  AND major.section_type = 'MAJOR'

  AND (

    (
        home.section_code = '41001'
        AND major.section_code = '41004'
    )

    OR

    (
        home.section_code = '41002'
        AND major.section_code = '41005'
    )

    OR

    (
        home.section_code = '41003'
        AND major.section_code = '41006'
    )

  )

  AND NOT EXISTS (

    SELECT 1

    FROM section_major_links AS existing

    WHERE existing.home_section_id =
          home.section_id

      AND existing.major_section_id =
          major.section_id

);


COMMIT;


-- =============================================
-- FINAL VERIFICATION
-- =============================================

SELECT

    p.program_code,
    ap.academic_year,
    ap.semester,

    sec.section_code,
    sec.year_level,
    sec.section_type,
    sec.student_count,

    COUNT(ss.subject_id) AS subject_count

FROM sections AS sec

INNER JOIN programs AS p
    ON p.program_id = sec.program_id

INNER JOIN academic_periods AS ap
    ON ap.academic_period_id =
       sec.academic_period_id

LEFT JOIN section_subjects AS ss
    ON ss.section_id = sec.section_id

WHERE ap.academic_year = '2026-2027'
  AND ap.semester = 1
  AND p.program_code = 'BSIT'

GROUP BY

    p.program_code,
    ap.academic_year,
    ap.semester,

    sec.section_id,
    sec.section_code,
    sec.year_level,
    sec.section_type,
    sec.student_count

ORDER BY sec.section_code;