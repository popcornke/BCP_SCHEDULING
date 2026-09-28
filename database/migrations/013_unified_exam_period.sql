-- ============================================================
-- BCP Module 4 - Unified examination period per academic period
-- MariaDB 10.4+
-- Reuses academic_period_calendars; no new table is created.
-- ============================================================

ALTER TABLE academic_period_calendars
    ADD COLUMN IF NOT EXISTS exam_day_1 DATE NULL AFTER teaching_end_date,
    ADD COLUMN IF NOT EXISTS exam_day_2 DATE NULL AFTER exam_day_1,
    ADD COLUMN IF NOT EXISTS exam_day_3 DATE NULL AFTER exam_day_2;

-- Ensure each academic period has exactly one calendar row.
INSERT INTO academic_period_calendars (academic_period_id, calendar_status)
SELECT ap.academic_period_id, 'PENDING'
FROM academic_periods ap
LEFT JOIN academic_period_calendars c
    ON c.academic_period_id = ap.academic_period_id
WHERE c.academic_period_id IS NULL;

-- Backfill the unified dates from existing ACTIVE exam batches only when
-- all ACTIVE program batches in the same academic period already agree.
UPDATE academic_period_calendars c
JOIN (
    SELECT
        academic_period_id,
        MIN(exam_day_1) AS exam_day_1,
        MIN(exam_day_2) AS exam_day_2,
        MIN(exam_day_3) AS exam_day_3,
        COUNT(DISTINCT CONCAT(
            DATE_FORMAT(exam_day_1, '%Y-%m-%d'), '|',
            DATE_FORMAT(exam_day_2, '%Y-%m-%d'), '|',
            DATE_FORMAT(exam_day_3, '%Y-%m-%d')
        )) AS date_sets
    FROM exam_batches
    WHERE status = 'ACTIVE'
    GROUP BY academic_period_id
    HAVING date_sets = 1
) eb ON eb.academic_period_id = c.academic_period_id
SET
    c.exam_day_1 = COALESCE(c.exam_day_1, eb.exam_day_1),
    c.exam_day_2 = COALESCE(c.exam_day_2, eb.exam_day_2),
    c.exam_day_3 = COALESCE(c.exam_day_3, eb.exam_day_3);

-- Verification: every row should have either 0 or 3 exam dates populated.
SELECT
    c.academic_period_id,
    ap.academic_year,
    ap.semester,
    c.exam_day_1,
    c.exam_day_2,
    c.exam_day_3,
    (
        SELECT COUNT(*)
        FROM exam_batches eb
        WHERE eb.academic_period_id = c.academic_period_id
          AND eb.status = 'ACTIVE'
    ) AS active_exam_batches
FROM academic_period_calendars c
JOIN academic_periods ap
    ON ap.academic_period_id = c.academic_period_id
ORDER BY c.academic_period_id;
