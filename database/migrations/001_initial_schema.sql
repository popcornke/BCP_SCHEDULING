USE bcp_scheduling;

CREATE TABLE programs ( 
    program_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(30) NOT NULL, 
    program_name VARCHAR(200) NOT NULL,
    education_level VARCHAR(50) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_program_code (program_code)
) ENGINE=InnoDB; -- Added missing semicolon here

CREATE TABLE subjects ( 
    subject_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id INT UNSIGNED NOT NULL,
    subject_code VARCHAR(50) NOT NULL, 
    subject_title VARCHAR(255) NOT NULL,
    units DECIMAL(5,2) NOT NULL, 
    f2f_hours DECIMAL(5,2) NOT NULL, 
    online_hours DECIMAL(5,2) NOT NULL,
    year_level VARCHAR(50) NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    legacy_subject_id INT UNSIGNED NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_subject_program FOREIGN KEY (program_id) REFERENCES programs(program_id),
    CONSTRAINT chk_subject_units CHECK (units >= 0),
    CONSTRAINT chk_subject_f2f CHECK (f2f_hours >= 0),
    CONSTRAINT chk_subject_online CHECK (online_hours >= 0),
    CONSTRAINT chk_subject_semester CHECK (semester BETWEEN 1 AND 3),
    
    UNIQUE KEY uq_legacy_subject (legacy_subject_id),
    INDEX idx_subject_program (program_id),
    INDEX idx_subject_period (program_id, year_level, semester)
) ENGINE=InnoDB;