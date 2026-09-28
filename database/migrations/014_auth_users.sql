CREATE TABLE IF NOT EXISTS auth_users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    username VARCHAR(80) NOT NULL,

    password_hash VARCHAR(255) NOT NULL,

    role ENUM('ADMIN', 'SCHEDULER')
        NOT NULL DEFAULT 'SCHEDULER',

    is_active TINYINT(1)
        NOT NULL DEFAULT 1,

    failed_attempts INT UNSIGNED
        NOT NULL DEFAULT 0,

    locked_until DATETIME NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_auth_username (username),

    INDEX idx_auth_active (is_active),

    INDEX idx_auth_locked (locked_until)
) ENGINE=InnoDB;