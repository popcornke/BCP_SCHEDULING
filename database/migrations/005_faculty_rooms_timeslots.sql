
USE bcp_scheduling;

-- ============================================
-- PHASE 2B
-- FACULTY, ROOMS AND TIME SLOTS
-- ============================================


-- ============================================
-- 1. TEACHERS
-- ============================================

CREATE TABLE IF NOT EXISTS teachers (

    teacher_id INT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,

    program_id INT UNSIGNED NOT NULL,

    employee_no VARCHAR(50) NOT NULL,

    teacher_name VARCHAR(150) NOT NULL,

    demo_no TINYINT UNSIGNED NULL,

    max_daily_hours TINYINT UNSIGNED
        NOT NULL DEFAULT 8,

    max_weekly_hours TINYINT UNSIGNED
        NOT NULL DEFAULT 30,

    status ENUM(
        'ACTIVE',
        'INACTIVE'
    ) NOT NULL DEFAULT 'ACTIVE',

    data_origin ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL DEFAULT 'DEMO',

    created_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_teacher_program
        FOREIGN KEY (program_id)
        REFERENCES programs(program_id),

    UNIQUE KEY uq_teacher_employee (
        employee_no
    ),

    UNIQUE KEY uq_teacher_demo (
        program_id,
        demo_no
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 2. AUTHORIZED TEACHER SUBJECTS
-- ============================================

CREATE TABLE IF NOT EXISTS
teacher_subject_authorizations (

    authorization_id INT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,

    teacher_id INT UNSIGNED NOT NULL,

    subject_id INT UNSIGNED NOT NULL,

    data_origin ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL DEFAULT 'DEMO',

    created_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_auth_teacher
        FOREIGN KEY (teacher_id)
        REFERENCES teachers(teacher_id),

    CONSTRAINT fk_auth_subject
        FOREIGN KEY (subject_id)
        REFERENCES subjects(subject_id),

    UNIQUE KEY uq_teacher_subject (
        teacher_id,
        subject_id
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 3. TEACHER AVAILABILITY
-- ============================================

CREATE TABLE IF NOT EXISTS teacher_availability (

    availability_id INT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,

    teacher_id INT UNSIGNED NOT NULL,

    academic_period_id INT UNSIGNED NOT NULL,

    day_of_week ENUM(
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday'
    ) NOT NULL,

    start_time TIME NOT NULL,

    end_time TIME NOT NULL,

    availability_status ENUM(
        'AVAILABLE',
        'UNAVAILABLE'
    ) NOT NULL DEFAULT 'AVAILABLE',

    data_origin ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL DEFAULT 'DEMO',

    CONSTRAINT fk_ta_teacher
        FOREIGN KEY (teacher_id)
        REFERENCES teachers(teacher_id),

    CONSTRAINT fk_ta_period
        FOREIGN KEY (academic_period_id)
        REFERENCES academic_periods(
            academic_period_id
        ),

    CONSTRAINT chk_teacher_availability_time
        CHECK (end_time > start_time),

    UNIQUE KEY uq_teacher_availability (
        teacher_id,
        academic_period_id,
        day_of_week,
        start_time,
        end_time
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 4. ROOMS
-- ============================================

CREATE TABLE IF NOT EXISTS rooms (

    room_id INT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,

    program_id INT UNSIGNED NULL,

    room_name VARCHAR(100) NOT NULL,

    building VARCHAR(150) NULL,

    capacity SMALLINT UNSIGNED
        NOT NULL DEFAULT 50,

    room_type ENUM(
        'GENERAL',
        'LABORATORY',
        'SPECIALIZED'
    ) NOT NULL DEFAULT 'GENERAL',

    status ENUM(
        'AVAILABLE',
        'UNAVAILABLE',
        'MAINTENANCE'
    ) NOT NULL DEFAULT 'AVAILABLE',

    data_origin ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL DEFAULT 'DEMO',

    created_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_room_program
        FOREIGN KEY (program_id)
        REFERENCES programs(program_id),

    CONSTRAINT chk_room_capacity
        CHECK (capacity > 0),

    UNIQUE KEY uq_room_name (
        room_name
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 5. ROOM AVAILABILITY
-- ============================================

CREATE TABLE IF NOT EXISTS room_availability (

    availability_id INT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,

    room_id INT UNSIGNED NOT NULL,

    academic_period_id INT UNSIGNED NOT NULL,

    day_of_week ENUM(
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday'
    ) NOT NULL,

    start_time TIME NOT NULL,

    end_time TIME NOT NULL,

    availability_status ENUM(
        'AVAILABLE',
        'UNAVAILABLE'
    ) NOT NULL DEFAULT 'AVAILABLE',

    data_origin ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL DEFAULT 'DEMO',

    CONSTRAINT fk_ra_room
        FOREIGN KEY (room_id)
        REFERENCES rooms(room_id),

    CONSTRAINT fk_ra_period
        FOREIGN KEY (academic_period_id)
        REFERENCES academic_periods(
            academic_period_id
        ),

    CONSTRAINT chk_room_availability_time
        CHECK (end_time > start_time),

    UNIQUE KEY uq_room_availability (
        room_id,
        academic_period_id,
        day_of_week,
        start_time,
        end_time
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 6. OFFICIAL TIME SLOT SOURCE
-- ============================================

CREATE TABLE IF NOT EXISTS time_slots (

    time_slot_id INT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,

    day_of_week ENUM(
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday'
    ) NOT NULL,

    day_pattern ENUM(
        'MWF',
        'TTHS'
    ) NOT NULL,

    start_time TIME NOT NULL,

    end_time TIME NOT NULL,

    is_active TINYINT(1)
        NOT NULL DEFAULT 1,

    data_origin ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL DEFAULT 'DEMO',

    CONSTRAINT chk_time_slot_duration
        CHECK (end_time > start_time),

    UNIQUE KEY uq_time_slot (
        day_of_week,
        start_time,
        end_time,
        data_origin
    ),

    INDEX idx_time_slot_day (
        day_of_week,
        start_time,
        end_time
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;