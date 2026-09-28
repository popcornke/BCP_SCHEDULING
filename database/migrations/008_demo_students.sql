-- BCP Module 1: DEMO student roster for READ-ONLY student timetable viewing.
-- Run in phpMyAdmin against the NEW bcp_scheduling DB, NOT the old sms_db.
-- Never run this seed for OFFICIAL sections or an integrated official students table.
USE bcp_scheduling;

CREATE TABLE IF NOT EXISTS students (
    student_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_number VARCHAR(40) NOT NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    program_id INT UNSIGNED NOT NULL,
    academic_period_id INT UNSIGNED NOT NULL,
    home_section_id INT UNSIGNED NOT NULL,
    major_section_id INT UNSIGNED DEFAULT NULL,
    data_origin ENUM('DEMO','OFFICIAL') NOT NULL DEFAULT 'DEMO',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id),
    UNIQUE KEY uq_student_period (academic_period_id, student_number),
    KEY idx_student_roster (program_id, academic_period_id, home_section_id),
    KEY idx_student_major (major_section_id),
    CONSTRAINT fk_demo_student_program FOREIGN KEY (program_id) REFERENCES programs(program_id),
    CONSTRAINT fk_demo_student_period FOREIGN KEY (academic_period_id) REFERENCES academic_periods(academic_period_id),
    CONSTRAINT fk_demo_student_home FOREIGN KEY (home_section_id) REFERENCES sections(section_id),
    CONSTRAINT fk_demo_student_major FOREIGN KEY (major_section_id) REFERENCES sections(section_id),
    CONSTRAINT chk_demo_student_distinct_sections CHECK (major_section_id IS NULL OR major_section_id <> home_section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IMPORTANT: Before import, inspect these results. Expected: 10 REGULAR/CLUSTER sections,
-- exactly three one-to-one CLUSTER -> MAJOR links with linked_student_count=50.
SELECT s.section_code, s.section_type, s.student_count,
       m.section_code AS linked_major, l.linked_student_count
FROM sections s
JOIN programs p ON p.program_id=s.program_id
JOIN academic_periods ap ON ap.academic_period_id=s.academic_period_id
LEFT JOIN section_major_links l ON l.home_section_id=s.section_id AND l.data_origin='DEMO'
LEFT JOIN sections m ON m.section_id=l.major_section_id
WHERE p.program_code='BSIT' AND ap.academic_year='2026-2027' AND ap.semester=1
  AND s.data_origin='DEMO' AND s.is_active=1 AND s.section_type IN ('REGULAR','CLUSTER')
ORDER BY s.year_level, s.section_code;

-- Seed 50 students per existing REGULAR or CLUSTER section (7 + 3 sections).
-- Year-four students are ONE record each, with a second MAJOR section reference.
-- Unique student numbers and NOT EXISTS make the seed rerunnable without duplicates.
-- This inserts only if the demo section set and one-to-one cluster-major links match
-- the explicitly agreed sample setup. If your data differs, review it first.
INSERT INTO students (
  student_number, first_name, last_name, program_id, academic_period_id,
  home_section_id, major_section_id, data_origin
)
SELECT
  CONCAT('DEMO-BSIT-2026S1-', LPAD(((base.ordinal - 1) * 50 + numbers.seq), 4, '0')),
  'Demo',
  CONCAT('Student ', LPAD(((base.ordinal - 1) * 50 + numbers.seq), 4, '0')),
  base.program_id, base.academic_period_id, base.section_id,
  base.major_section_id, 'DEMO'
FROM (
  SELECT s.section_id, s.program_id, s.academic_period_id,
         ROW_NUMBER() OVER (ORDER BY s.year_level, s.section_code, s.section_id) AS ordinal,
         COUNT(*) OVER () AS eligible_section_count,
         SUM(CASE WHEN s.section_type='CLUSTER' THEN 1 ELSE 0 END) OVER () AS valid_cluster_count,
         ml.major_section_id
  FROM sections s
  JOIN programs p ON p.program_id=s.program_id
  JOIN academic_periods ap ON ap.academic_period_id=s.academic_period_id
  LEFT JOIN (
      SELECT home_section_id, MIN(major_section_id) AS major_section_id,
             COUNT(*) AS link_count, MIN(linked_student_count) AS member_count
      FROM section_major_links WHERE data_origin='DEMO'
      GROUP BY home_section_id
  ) ml ON ml.home_section_id=s.section_id
  WHERE p.program_code='BSIT' AND ap.academic_year='2026-2027' AND ap.semester=1
    AND ap.period_status='DEMO' AND s.data_origin='DEMO' AND s.is_active=1
    AND s.section_type IN ('REGULAR','CLUSTER') AND s.student_count=50
    AND (
      (s.section_type='REGULAR' AND ml.major_section_id IS NULL)
      OR
      (s.section_type='CLUSTER' AND ml.link_count=1 AND ml.member_count=50
        AND EXISTS (
          SELECT 1 FROM sections ms
          WHERE ms.section_id=ml.major_section_id
            AND ms.section_type='MAJOR' AND ms.data_origin='DEMO' AND ms.is_active=1
            AND ms.program_id=s.program_id AND ms.academic_period_id=s.academic_period_id
            AND ms.student_count=50
        ))
    )
) AS base
CROSS JOIN (
  SELECT tens.n * 10 + ones.n + 1 AS seq
  FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) tens
  CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
              UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) ones
) AS numbers
WHERE base.eligible_section_count = 10 AND base.valid_cluster_count = 3
  AND (SELECT COUNT(*) FROM sections s
       JOIN programs p ON p.program_id=s.program_id
       JOIN academic_periods ap ON ap.academic_period_id=s.academic_period_id
       WHERE p.program_code='BSIT' AND ap.academic_year='2026-2027' AND ap.semester=1
         AND ap.period_status='DEMO' AND s.data_origin='DEMO' AND s.is_active=1
         AND s.section_type IN ('REGULAR','CLUSTER') AND s.student_count=50) = 10
  AND NOT EXISTS (
    SELECT 1 FROM students existing
    WHERE existing.academic_period_id=base.academic_period_id
      AND existing.student_number=CONCAT('DEMO-BSIT-2026S1-', LPAD(((base.ordinal - 1) * 50 + numbers.seq), 4, '0'))
  );

-- Verify: 500 distinct DEMO students, 150 with MAJOR, and 50 students per home section.
SELECT COUNT(*) AS demo_students,
       COUNT(DISTINCT student_number) AS unique_student_numbers,
       SUM(major_section_id IS NOT NULL) AS fourth_year_with_major
FROM students WHERE data_origin='DEMO' AND student_number LIKE 'DEMO-BSIT-2026S1-%';

SELECT home.section_code AS home_section, home.section_type,
       COALESCE(major.section_code, '—') AS major_section,
       COUNT(*) AS actual_students
FROM students st
JOIN sections home ON home.section_id=st.home_section_id
LEFT JOIN sections major ON major.section_id=st.major_section_id
WHERE st.data_origin='DEMO' AND st.student_number LIKE 'DEMO-BSIT-2026S1-%'
GROUP BY home.section_id, home.section_code, home.section_type, major.section_code
ORDER BY home.section_code;
