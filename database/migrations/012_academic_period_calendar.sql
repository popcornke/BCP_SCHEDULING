-- BCP Module 6 / Phase 6E. Run in bcp_scheduling after exporting a backup.
-- Does NOT insert assumed semester dates, approve any policy, or modify Modules 1-5.
USE bcp_scheduling;

CREATE TABLE IF NOT EXISTS bcp_scheduling.academic_period_calendars (
    academic_period_calendar_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    academic_period_id INT UNSIGNED NOT NULL,
    teaching_start_date DATE NULL,
    teaching_end_date DATE NULL,
    calendar_status ENUM('PENDING','APPROVED','REVOKED') NOT NULL DEFAULT 'PENDING',
    approval_reference VARCHAR(150) NULL,
    approved_by VARCHAR(150) NULL,
    approval_evidence_sha256 CHAR(64) NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (academic_period_calendar_id),
    UNIQUE KEY uq_calendar_period (academic_period_id),
    CONSTRAINT fk_calendar_academic_period FOREIGN KEY (academic_period_id)
        REFERENCES bcp_scheduling.academic_periods (academic_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No calendar row is automatically APPROVED or pre-populated.
SELECT academic_period_id, teaching_start_date, teaching_end_date, calendar_status
FROM bcp_scheduling.academic_period_calendars ORDER BY academic_period_id;
