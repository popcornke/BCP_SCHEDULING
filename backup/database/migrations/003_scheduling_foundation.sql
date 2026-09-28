
USE bcp_scheduling;

-- =============================================
-- 1. ACADEMIC PERIODS
-- =============================================

CREATE TABLE IF NOT EXISTS academic_periods (

    academic_period_id INT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,

    academic_year VARCHAR(9) NOT NULL,

    semester TINYINT UNSIGNED NOT NULL,

    period_status ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL DEFAULT 'DEMO',

    created_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_academic_semester
        CHECK (semester IN (1, 2)),

    UNIQUE KEY uq_academic_period (
        academic_year,
        semester
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =============================================
-- 2. SECTIONS
-- =============================================

CREATE TABLE IF NOT EXISTS sections (

    section_id INT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,

    academic_period_id INT UNSIGNED NOT NULL,

    program_id INT UNSIGNED NOT NULL,

    section_code CHAR(5) NOT NULL,

    year_level TINYINT UNSIGNED NOT NULL,

    section_type ENUM(
        'REGULAR',
        'CLUSTER',
        'MAJOR'
    ) NOT NULL DEFAULT 'REGULAR',

    student_count SMALLINT UNSIGNED
        NOT NULL DEFAULT 50,

    data_origin ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL DEFAULT 'DEMO',

    is_active TINYINT(1)
        NOT NULL DEFAULT 1,

    created_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_section_academic_period
        FOREIGN KEY (academic_period_id)
        REFERENCES academic_periods(
            academic_period_id
        ),

    CONSTRAINT fk_section_program
        FOREIGN KEY (program_id)
        REFERENCES programs(program_id),

    CONSTRAINT chk_section_year
        CHECK (year_level BETWEEN 1 AND 4),

    CONSTRAINT chk_section_code
        CHECK (
            CHAR_LENGTH(section_code) = 5
            AND section_code REGEXP '^[1-4][12]0[0-9]{2}$'
        ),

    UNIQUE KEY uq_section_identity (
        academic_period_id,
        program_id,
        section_code
    ),

    INDEX idx_section_program (
        program_id,
        academic_period_id
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =============================================
-- 3. SECTION SUBJECTS
-- =============================================

CREATE TABLE IF NOT EXISTS section_subjects (

    section_subject_id INT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,

    section_id INT UNSIGNED NOT NULL,

    subject_id INT UNSIGNED NOT NULL,

    data_origin ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL DEFAULT 'DEMO',

    created_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ss_section
        FOREIGN KEY (section_id)
        REFERENCES sections(section_id),

    CONSTRAINT fk_ss_subject
        FOREIGN KEY (subject_id)
        REFERENCES subjects(subject_id),

    UNIQUE KEY uq_section_subject (
        section_id,
        subject_id
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =============================================
-- 4. CLUSTER / MAJOR RELATIONSHIPS
-- =============================================

CREATE TABLE IF NOT EXISTS section_major_links (

    link_id INT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,

    home_section_id INT UNSIGNED NOT NULL,

    major_section_id INT UNSIGNED NOT NULL,

    linked_student_count SMALLINT UNSIGNED
        NOT NULL,

    data_origin ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL DEFAULT 'DEMO',

    created_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_major_home
        FOREIGN KEY (home_section_id)
        REFERENCES sections(section_id),

    CONSTRAINT fk_major_section
        FOREIGN KEY (major_section_id)
        REFERENCES sections(section_id),

    CONSTRAINT chk_link_student_count
        CHECK (linked_student_count > 0),

    CONSTRAINT chk_different_sections
        CHECK (
            home_section_id <> major_section_id
        ),

    UNIQUE KEY uq_home_major (
        home_section_id,
        major_section_id
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;