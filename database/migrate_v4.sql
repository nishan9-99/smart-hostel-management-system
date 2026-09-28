-- v3.2 -> v4; safe to rerun. Back up your database before applying any schema migration.
USE smart_hostel;
-- Last reconciliation before removing duplicated names. Rows with missing users retain their FK;
-- the name cannot be reconstructed when an old ON DELETE SET NULL already removed it.
SET @has_c_name := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='complaints' AND COLUMN_NAME='student_name');
SET @sql := IF(@has_c_name>0, 'UPDATE complaints c JOIN users u ON u.id=c.user_id SET c.student_name=u.full_name WHERE c.student_name IS NULL OR c.student_name<>u.full_name', 'SELECT 1');
PREPARE m FROM @sql; EXECUTE m; DEALLOCATE PREPARE m;
SET @has_p_name := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND COLUMN_NAME='student_name');
SET @sql := IF(@has_p_name>0, 'UPDATE payments p JOIN users u ON u.id=p.student_id SET p.student_name=u.full_name WHERE p.student_name IS NULL OR p.student_name<>u.full_name', 'SELECT 1');
PREPARE m FROM @sql; EXECUTE m; DEALLOCATE PREPARE m;
SET @sql := IF(@has_c_name>0, 'ALTER TABLE complaints DROP COLUMN student_name', 'SELECT 1');
PREPARE m FROM @sql; EXECUTE m; DEALLOCATE PREPARE m;
SET @sql := IF(@has_p_name>0, 'ALTER TABLE payments DROP COLUMN student_name', 'SELECT 1');
PREPARE m FROM @sql; EXECUTE m; DEALLOCATE PREPARE m;
DELIMITER $$
DROP PROCEDURE IF EXISTS assign_fee$$
CREATE PROCEDURE assign_fee(
    IN p_student_id INT,
    IN p_fee_type   VARCHAR(100),
    IN p_amount     DECIMAL(10,2),
    IN p_due_date   DATE
)
BEGIN
    DECLARE v_name VARCHAR(100);
    DECLARE v_role VARCHAR(20);

    SELECT full_name, role INTO v_name, v_role
    FROM users WHERE id = p_student_id AND status = 'active' LIMIT 1;

    IF v_name IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Student not found or inactive';
    ELSEIF v_role <> 'student' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fees can only be assigned to students';
    ELSEIF p_amount <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Amount must be greater than zero';
    ELSE
        INSERT INTO payments
            (student_id, fee_type, amount, status, verification_status, due_date)
        VALUES
            (p_student_id, p_fee_type, p_amount, 'pending', 'Pending', p_due_date);
    END IF;
END$$
DELIMITER ;
CREATE TABLE IF NOT EXISTS reset_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_attempt (email, ip, attempted_at)
);
CREATE TABLE IF NOT EXISTS admin_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(80) NOT NULL,
    target_id INT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_admin (admin_id),
    INDEX idx_audit_action_date (action,created_at),
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
);
ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','pending_verification') NOT NULL DEFAULT 'active';
CREATE TABLE IF NOT EXISTS registration_verifications (
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL UNIQUE,
 otp_hash VARCHAR(255) NOT NULL,
 expires_at DATETIME NOT NULL,
 attempts TINYINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
SET @has_target := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='target_user_id');
SET @sql := IF(@has_target>0, 'SELECT 1', 'ALTER TABLE notifications ADD COLUMN target_user_id INT NULL, ADD INDEX idx_notification_target (target_user_id), ADD CONSTRAINT fk_notification_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE');
PREPARE m FROM @sql; EXECUTE m; DEALLOCATE PREPARE m;
CREATE TABLE IF NOT EXISTS notification_reads (
    user_id INT NOT NULL,
    notification_id INT NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,notification_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
);
SET @has_flag := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visitors' AND COLUMN_NAME='flagged');
SET @sql := IF(@has_flag>0, 'SELECT 1', 'ALTER TABLE visitors ADD COLUMN flagged TINYINT(1) NOT NULL DEFAULT 0');
PREPARE m FROM @sql; EXECUTE m; DEALLOCATE PREPARE m;
SET @has_priority := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='complaints' AND COLUMN_NAME='priority');
SET @sql := IF(@has_priority>0, 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN priority ENUM(''Low'',''Normal'',''High'') NOT NULL DEFAULT ''Normal''');
PREPARE m FROM @sql; EXECUTE m; DEALLOCATE PREPARE m;
DELIMITER $$
-- Flag visitors still inside after 12 hours. Change the INTERVAL to tune the threshold.
CREATE EVENT IF NOT EXISTS ev_flag_long_visits
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO UPDATE visitors SET flagged=1 WHERE status='Inside' AND flagged=0 AND entry_time < DATE_SUB(NOW(), INTERVAL 12 HOUR)$$
DELIMITER ;
