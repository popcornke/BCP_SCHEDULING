USE bcp_scheduling;
-- Phase 6A: storage foundation. Does not insert, delete, or modify existing scheduling records.
CREATE TABLE IF NOT EXISTS special_classes (
 special_class_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 academic_period_id INT UNSIGNED NOT NULL,
 program_id INT UNSIGNED NOT NULL,
 subject_id INT UNSIGNED NOT NULL,
 class_type ENUM('REMEDIAL','IRREGULAR','OCTOBERIAN') NOT NULL,
 recurrence ENUM('WEEKLY') NOT NULL DEFAULT 'WEEKLY',
 teacher_id INT UNSIGNED NULL,
 approved_duration_minutes SMALLINT UNSIGNED NULL,
 approval_reference VARCHAR(150) NULL,
 status ENUM('DRAFT','ACTIVE','CANCELLED') NOT NULL DEFAULT 'DRAFT',
 data_origin ENUM('DEMO','OFFICIAL') NOT NULL DEFAULT 'DEMO',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_sc_period FOREIGN KEY (academic_period_id) REFERENCES academic_periods (academic_period_id),
 CONSTRAINT fk_sc_program FOREIGN KEY (program_id) REFERENCES programs (program_id),
 CONSTRAINT fk_sc_subject FOREIGN KEY (subject_id) REFERENCES subjects (subject_id),
 CONSTRAINT fk_sc_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (teacher_id),
 CONSTRAINT chk_sc_active_approval CHECK (status <> 'ACTIVE' OR (approved_duration_minutes IS NOT NULL AND approved_duration_minutes > 0 AND approval_reference IS NOT NULL AND CHAR_LENGTH(TRIM(approval_reference)) > 0)),
 KEY idx_sc_period (academic_period_id,program_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS special_class_students (
 special_class_id BIGINT UNSIGNED NOT NULL,
 student_number VARCHAR(80) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (special_class_id,student_number),
 CONSTRAINT fk_scs_class FOREIGN KEY (special_class_id) REFERENCES special_classes(special_class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Student numbers are stored as references without a foreign key until the live students PK/unique schema is verified.

CREATE TABLE IF NOT EXISTS special_class_meetings (
 special_meeting_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 special_class_id BIGINT UNSIGNED NOT NULL,
 meeting_date DATE NOT NULL,
 start_time TIME NOT NULL,
 end_time TIME NOT NULL,
 room_id INT UNSIGNED NULL,
 teacher_id INT UNSIGNED NOT NULL,
 delivery_mode ENUM('F2F','ONLINE') NOT NULL,
 status ENUM('SCHEDULED','CANCELLED') NOT NULL DEFAULT 'SCHEDULED',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_special_weekly_meeting (special_class_id,meeting_date,start_time),
 KEY idx_special_date_teacher (meeting_date,teacher_id,start_time,end_time),
 KEY idx_special_date_room (meeting_date,room_id,start_time,end_time),
 CONSTRAINT fk_scm_class FOREIGN KEY (special_class_id) REFERENCES special_classes(special_class_id),
 CONSTRAINT fk_scm_room FOREIGN KEY (room_id) REFERENCES rooms(room_id),
 CONSTRAINT fk_scm_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id),
 CONSTRAINT chk_special_time CHECK (end_time > start_time),
 CONSTRAINT chk_special_room CHECK ((delivery_mode='ONLINE' AND room_id IS NULL) OR (delivery_mode='F2F' AND room_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT table_name FROM information_schema.tables WHERE table_schema='bcp_scheduling' AND table_name IN ('special_classes','special_class_students','special_class_meetings') ORDER BY table_name;
