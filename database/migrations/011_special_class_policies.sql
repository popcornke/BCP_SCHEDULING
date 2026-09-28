-- Module 6 Phase 6D: policy registry only, NOT an approval or class schedule.
-- Select bcp_scheduling in phpMyAdmin before importing. Back up database first.
-- Creates ONLY a NEW table. No inserts, updates or deletes.
USE bcp_scheduling;
CREATE TABLE IF NOT EXISTS special_class_policies (
    special_class_policy_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    academic_period_id INT UNSIGNED NOT NULL,
    program_id INT UNSIGNED NOT NULL,
    class_type ENUM('REMEDIAL','IRREGULAR','OCTOBERIAN') NOT NULL,
    recurrence ENUM('WEEKLY') NOT NULL DEFAULT 'WEEKLY',
    approved_duration_minutes SMALLINT UNSIGNED NULL,
    delivery_mode ENUM('F2F','ONLINE') NULL,
    policy_status ENUM('PENDING','APPROVED','REVOKED') NOT NULL DEFAULT 'PENDING',
    approval_reference VARCHAR(150) NULL,
    approved_by VARCHAR(150) NULL,
    approval_evidence_sha256 CHAR(64) NULL,
    approved_at DATETIME NULL,
    octoberian_specific_rules_approved TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(special_class_policy_id),
    UNIQUE KEY uq_special_policy_scope (academic_period_id,program_id,class_type),
    CONSTRAINT fk_special_policy_period FOREIGN KEY (academic_period_id)
        REFERENCES academic_periods(academic_period_id),
    CONSTRAINT fk_special_policy_program FOREIGN KEY (program_id)
        REFERENCES programs(program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- No policy is pre-approved or inserted by this script.
SELECT COUNT(*) AS policy_records FROM bcp_scheduling.special_class_policies;
