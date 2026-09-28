-- MODULE 5 / DEMO. Run in phpMyAdmin after selecting bcp_scheduling.
-- Creates ONLY a separate temporary substitute assignment/history table.
-- Does not UPDATE teachers, schedule_meetings, sections, or exam tables.
USE bcp_scheduling;

CREATE TABLE IF NOT EXISTS substitute_assignments (
    substitute_assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    meeting_id BIGINT UNSIGNED NOT NULL,
    class_batch_id BIGINT UNSIGNED NOT NULL,
    academic_period_id INT UNSIGNED NOT NULL,
    duty_date DATE NOT NULL,
    original_teacher_id INT UNSIGNED NOT NULL,
    substitute_teacher_id INT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('ACTIVE','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
    active_key TINYINT GENERATED ALWAYS AS (
        CASE WHEN status = 'ACTIVE' THEN 1 ELSE NULL END
    ) STORED,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    cancellation_reason VARCHAR(500) NULL,
    PRIMARY KEY (substitute_assignment_id),
    UNIQUE KEY uq_substitute_one_active (meeting_id, duty_date, active_key),
    KEY idx_substitute_period_date (academic_period_id, duty_date, status),
    KEY idx_substitute_teacher_date (substitute_teacher_id, duty_date, status),
    KEY idx_substitute_batch (class_batch_id),
    CONSTRAINT fk_sub_meeting FOREIGN KEY (meeting_id)
        REFERENCES schedule_meetings (meeting_id),
    CONSTRAINT fk_sub_batch FOREIGN KEY (class_batch_id)
        REFERENCES schedule_batches (batch_id),
    CONSTRAINT fk_sub_period FOREIGN KEY (academic_period_id)
        REFERENCES academic_periods (academic_period_id),
    CONSTRAINT fk_sub_original_teacher FOREIGN KEY (original_teacher_id)
        REFERENCES teachers (teacher_id),
    CONSTRAINT fk_sub_replacement_teacher FOREIGN KEY (substitute_teacher_id)
        REFERENCES teachers (teacher_id),
    CONSTRAINT chk_sub_teachers_differ CHECK (original_teacher_id <> substitute_teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'substitute_assignments' AS table_name, COUNT(*) AS saved_rows
FROM bcp_scheduling.substitute_assignments;
