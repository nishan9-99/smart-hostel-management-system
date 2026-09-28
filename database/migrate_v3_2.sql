-- One-time v3.1 -> v3.2 upgrade for existing database volumes.
-- Idempotent: safe to run again. Does not alter existing user data.
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_identifier_time (identifier, attempted_at)
);
