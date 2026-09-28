
-- ============================================
-- BCP AUTOMATIC CLASS SCHEDULING SYSTEM
--
-- PHASE 4A
-- SCHEDULE PERSISTENCE FOUNDATION
--
-- Creates storage tables only.
-- Does not save or modify existing schedules.
-- ============================================

USE bcp_scheduling;


-- ============================================
-- 1. SCHEDULE BATCHES
-- ============================================

CREATE TABLE IF NOT EXISTS schedule_batches (

    batch_id BIGINT UNSIGNED
        NOT NULL AUTO_INCREMENT,

    academic_period_id INT UNSIGNED
        NOT NULL,

    program_id INT UNSIGNED
        NOT NULL,

    data_origin ENUM(
        'DEMO',
        'OFFICIAL'
    ) NOT NULL,

    status ENUM(
        'ACTIVE',
        'SUPERSEDED'
    ) NOT NULL DEFAULT 'ACTIVE',

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (batch_id),

    KEY idx_batch_period_status (
        academic_period_id,
        status,
        program_id
    ),

    CONSTRAINT fk_schedule_batch_period
        FOREIGN KEY (academic_period_id)
        REFERENCES academic_periods(
            academic_period_id
        ),

    CONSTRAINT fk_schedule_batch_program
        FOREIGN KEY (program_id)
        REFERENCES programs(program_id)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 2. SCHEDULE MEETINGS
-- ============================================

CREATE TABLE IF NOT EXISTS schedule_meetings (

    meeting_id BIGINT UNSIGNED
        NOT NULL AUTO_INCREMENT,

    batch_id BIGINT UNSIGNED
        NOT NULL,

    section_subject_id INT UNSIGNED
        NOT NULL,

    teacher_id INT UNSIGNED
        NOT NULL,

    room_id INT UNSIGNED NULL,

    delivery_mode ENUM(
        'F2F',
        'ONLINE'
    ) NOT NULL,

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

    PRIMARY KEY (meeting_id),

    UNIQUE KEY uq_batch_subject_delivery (
        batch_id,
        section_subject_id,
        delivery_mode
    ),

    KEY idx_meeting_teacher_time (
        teacher_id,
        day_of_week,
        start_time,
        end_time
    ),

    KEY idx_meeting_room_time (
        room_id,
        day_of_week,
        start_time,
        end_time
    ),

    KEY idx_meeting_section_subject (
        section_subject_id
    ),

    CONSTRAINT fk_meeting_batch
        FOREIGN KEY (batch_id)
        REFERENCES schedule_batches(batch_id),

    CONSTRAINT fk_meeting_section_subject
        FOREIGN KEY (section_subject_id)
        REFERENCES section_subjects(
            section_subject_id
        ),

    CONSTRAINT fk_meeting_teacher
        FOREIGN KEY (teacher_id)
        REFERENCES teachers(teacher_id),

    CONSTRAINT fk_meeting_room
        FOREIGN KEY (room_id)
        REFERENCES rooms(room_id),

    CONSTRAINT chk_meeting_time
        CHECK (end_time > start_time),

    CONSTRAINT chk_meeting_room
        CHECK (
            (
                delivery_mode = 'ONLINE'
                AND room_id IS NULL
            )
            OR
            (
                delivery_mode = 'F2F'
                AND room_id IS NOT NULL
            )
        )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 3. VERIFY THE NEW TABLES
-- ============================================

SELECT
    'schedule_batches' AS table_name,
    COUNT(*) AS total
FROM schedule_batches

UNION ALL

SELECT
    'schedule_meetings',
    COUNT(*)
FROM schedule_meetings;