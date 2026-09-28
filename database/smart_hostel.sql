-- ============================================================
-- Smart Hostel Management System - Complete Database (v2)
-- Course: BCS403 | Semester IV | Branch: CSE
-- ------------------------------------------------------------
-- v2 changes:
--   * password_resets table (secure OTP password reset)
--   * visitors.student_id -> users(id) FK (no free-text match)
--   * payments.proof_file only (payment_proof column removed)
--   * explicit indexes: payments.student_id, complaints.user_id,
--     room_allocations.user_id
--   * real triggers (room occupancy, overdue fees)
--   * views (student_dues_view, room_occupancy_view)
--   * stored procedure assign_fee(...)
--   * overdue-fee event (needs event_scheduler, enabled in
--     docker-compose.yml)
-- ============================================================

CREATE DATABASE IF NOT EXISTS smart_hostel;
USE smart_hostel;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    full_name   VARCHAR(100)  NOT NULL,
    username    VARCHAR(30)   NOT NULL UNIQUE,
    email       VARCHAR(100)  NOT NULL UNIQUE,
    phone       VARCHAR(25)   NOT NULL,
    country     VARCHAR(100)  DEFAULT NULL,
    state       VARCHAR(100)  DEFAULT NULL,
    address     TEXT          DEFAULT NULL,
    password    VARCHAR(255)  NOT NULL,
    google_id   VARCHAR(255)  DEFAULT NULL UNIQUE,
    auth_provider ENUM('local','google') NOT NULL DEFAULT 'local',
    role        ENUM('admin','student') NOT NULL DEFAULT 'student',
    status      ENUM('active','inactive','pending_verification') NOT NULL DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Failed password logins, including identifiers not in users. OAuth bypasses this.
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_identifier_time (identifier, attempted_at)
);

CREATE TABLE IF NOT EXISTS reset_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_attempt (email, ip, attempted_at)
);

-- ============================================================
-- PASSWORD RESETS (secure OTP flow - stores HASHED otp only)
-- ============================================================
CREATE TABLE IF NOT EXISTS password_resets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    email       VARCHAR(100) NOT NULL,
    otp_hash    VARCHAR(255) NOT NULL,
    expires_at  DATETIME     NOT NULL,
    attempts    TINYINT      NOT NULL DEFAULT 0,
    used        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_user (user_id),
    INDEX idx_reset_email (email),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS registration_verifications (
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL UNIQUE,
 otp_hash VARCHAR(255) NOT NULL,
 expires_at DATETIME NOT NULL,
 attempts TINYINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- ROOMS
-- ============================================================
CREATE TABLE IF NOT EXISTS rooms (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    room_number  VARCHAR(20)  NOT NULL UNIQUE,
    room_type    VARCHAR(50)  NOT NULL,
    capacity     INT          NOT NULL DEFAULT 1,
    occupied     INT          NOT NULL DEFAULT 0,
    status       ENUM('Available','Full','Maintenance') NOT NULL DEFAULT 'Available',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- ROOM ALLOCATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS room_allocations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT  NOT NULL,
    room_id          INT  NOT NULL,
    allocation_date  DATE DEFAULT NULL,
    status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_alloc_user (user_id),
    INDEX idx_alloc_room (room_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
);

-- ============================================================
-- VISITORS (linked to students with a real foreign key)
-- ============================================================
CREATE TABLE IF NOT EXISTS visitors (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    student_id    INT          NOT NULL,
    visitor_name  VARCHAR(100) NOT NULL,
    visitor_phone VARCHAR(25)  DEFAULT NULL,
    relation      VARCHAR(100) NOT NULL,
    purpose       VARCHAR(255) DEFAULT NULL,
    visit_date    DATE         NOT NULL,
    entry_time    DATETIME     DEFAULT NULL,
    exit_time     DATETIME     DEFAULT NULL,
    status        ENUM('Inside','Exited') NOT NULL DEFAULT 'Inside',
    flagged       TINYINT(1) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visitor_student (student_id),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- COMPLAINTS
-- ============================================================
CREATE TABLE IF NOT EXISTS complaints (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          DEFAULT NULL,
    title         VARCHAR(150) NOT NULL,
    category      VARCHAR(80)  NOT NULL DEFAULT 'General',
    description   TEXT         NOT NULL,
    status        ENUM('Pending','In Progress','Resolved','Closed') NOT NULL DEFAULT 'Pending',
    priority      ENUM('Low','Normal','High') NOT NULL DEFAULT 'Normal',
    admin_reply   TEXT         DEFAULT NULL,
    resolved_at   DATETIME     DEFAULT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_complaints_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- PAYMENTS (single proof_file column)
-- ============================================================
CREATE TABLE IF NOT EXISTS payments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    student_id          INT           DEFAULT NULL,
    fee_type            VARCHAR(100)  NOT NULL,
    amount              DECIMAL(10,2) NOT NULL,
    status              ENUM('pending','paid','overdue','rejected') NOT NULL DEFAULT 'pending',
    verification_status VARCHAR(80)   NOT NULL DEFAULT 'Pending',
    payment_method      VARCHAR(100)  DEFAULT NULL,
    transaction_id      VARCHAR(100)  DEFAULT NULL,
    payment_note        VARCHAR(255)  DEFAULT NULL,
    proof_file          VARCHAR(255)  DEFAULT NULL,
    admin_remark        TEXT          DEFAULT NULL,
    due_date            DATE          NOT NULL,
    paid_date           DATETIME      DEFAULT NULL,
    verified_at         DATETIME      DEFAULT NULL,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payments_student (student_id),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- NOTIFICATIONS (broadcast - shown to all students)
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(150) NOT NULL,
    message     TEXT         NOT NULL,
    category    VARCHAR(80)  NOT NULL DEFAULT 'General',
    target_user_id INT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notification_target (target_user_id),
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notification_reads (
    user_id INT NOT NULL,
    notification_id INT NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,notification_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
);

-- ============================================================
-- DEFAULT ADMIN  (username: admin | password: Admin@123)
-- ============================================================
INSERT INTO users (full_name, username, email, phone, country, state, address, password, role, status)
VALUES (
    'System Admin',
    'admin',
    'admin@smarthostel.com',
    '+91 9000000000',
    'India',
    'Karnataka',
    'Smart Hostel, Main Campus',
    '$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa',
    'admin',
    'active'
);

-- ============================================================
-- SAMPLE ROOMS
-- ============================================================
-- All rooms start empty and available (104 is under maintenance).
-- occupied/status stay in sync automatically through the allocation triggers.
INSERT INTO rooms (room_number, room_type, capacity, occupied, status) VALUES
('101', 'Single',  1, 0, 'Available'),
('102', 'Double',  2, 0, 'Available'),
('103', 'Triple',  3, 0, 'Available'),
('104', 'Double',  2, 0, 'Maintenance'),
('201', 'Single',  1, 0, 'Available'),
('202', 'Double',  2, 0, 'Available'),
('203', 'Triple',  3, 0, 'Available'),
('301', 'Single',  1, 0, 'Available');

-- ============================================================
-- SAMPLE NOTIFICATIONS
-- ============================================================
INSERT INTO notifications (title, message, category) VALUES
('Welcome to Smart Hostel', 'Welcome to the Smart Hostel Management System. Keep your rooms clean and follow hostel rules at all times.', 'General'),
('Hostel Fee Reminder', 'Students are requested to clear pending hostel fees before the due date to avoid overdue charges.', 'Fee'),
('Hostel Meeting', 'All students must attend the hostel meeting in the common hall on Saturday at 6 PM sharp.', 'Event'),
('Water Supply Maintenance', 'Water supply will be unavailable from 10 AM to 12 PM on Sunday due to scheduled maintenance.', 'Maintenance'),
('Mess Fee Due', 'Monthly mess fee is due by end of this month. Please clear your dues to avoid penalties.', 'Fee');

-- Disposable demo-only seed accounts. Change demo credentials for any nonlocal deployment.
INSERT INTO users (full_name,username,email,phone,country,state,address,password,role,status) VALUES
('Aarav Mehta','demo01','demo01@example.test','+91 9900000001','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Aditi Rao','demo02','demo02@example.test','+91 9900000002','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Ananya Shah','demo03','demo03@example.test','+91 9900000003','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Arjun Nair','demo04','demo04@example.test','+91 9900000004','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Dev Patel','demo05','demo05@example.test','+91 9900000005','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Diya Menon','demo06','demo06@example.test','+91 9900000006','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Ishaan Verma','demo07','demo07@example.test','+91 9900000007','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Kavya Iyer','demo08','demo08@example.test','+91 9900000008','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Neha Kulkarni','demo09','demo09@example.test','+91 9900000009','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Nikhil Jain','demo10','demo10@example.test','+91 9900000010','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Pooja Sharma','demo11','demo11@example.test','+91 9900000011','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Pranav Das','demo12','demo12@example.test','+91 9900000012','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Riya Sethi','demo13','demo13@example.test','+91 9900000013','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Rohan Kumar','demo14','demo14@example.test','+91 9900000014','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Sana Khan','demo15','demo15@example.test','+91 9900000015','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Tanvi Gupta','demo16','demo16@example.test','+91 9900000016','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Vedant Singh','demo17','demo17@example.test','+91 9900000017','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active'),
('Zoya Ali','demo18','demo18@example.test','+91 9900000018','India','Karnataka','Demo hostel','$2y$10$gaQa/aHVudZXTGnA/uxMEe8F0S.643rBIYnVXU6Hpuqi/A.x5phLa','student','active');
INSERT INTO payments (student_id,fee_type,amount,status,verification_status,due_date,paid_date,verified_at)
SELECT u.id,'Hostel Fee',8000,
 CASE MOD(u.id,4) WHEN 0 THEN 'paid' WHEN 1 THEN 'pending' WHEN 2 THEN 'overdue' ELSE 'pending' END,
 CASE MOD(u.id,4) WHEN 0 THEN 'Approved' WHEN 1 THEN 'Pending Verification' WHEN 2 THEN 'Pending' ELSE 'Rejected' END,
 DATE_ADD(CURDATE(),INTERVAL (CASE MOD(u.id,4) WHEN 2 THEN -10 ELSE 20 END) DAY),
 CASE WHEN MOD(u.id,4)=0 THEN NOW() ELSE NULL END,
 CASE WHEN MOD(u.id,4)=0 THEN NOW() ELSE NULL END
FROM users u WHERE u.username LIKE 'demo%';
INSERT INTO complaints (user_id,title,category,description,status,priority,admin_reply,resolved_at)
SELECT id,CONCAT('Demo concern ',username),'Maintenance','Example complaint for the course demo.',
 CASE MOD(id,3) WHEN 0 THEN 'Resolved' WHEN 1 THEN 'Pending' ELSE 'In Progress' END,
 CASE MOD(id,3) WHEN 0 THEN 'Low' WHEN 1 THEN 'High' ELSE 'Normal' END,
 CASE WHEN MOD(id,3)=0 THEN 'Completed by maintenance.' ELSE NULL END,
 CASE WHEN MOD(id,3)=0 THEN NOW() ELSE NULL END
FROM users WHERE username IN ('demo01','demo03','demo06','demo09','demo12');
INSERT INTO visitors (student_id,visitor_name,visitor_phone,relation,purpose,visit_date,entry_time,exit_time,status)
SELECT id, CONCAT('Visitor for ',username),'+91 9000000123','Friend','Campus visit',CURDATE(),
 DATE_SUB(NOW(),INTERVAL 1 HOUR),NULL,'Inside'
FROM users WHERE username IN ('demo01','demo04','demo08');

-- ============================================================
-- TRIGGERS
-- ============================================================
DELIMITER $$

-- 1) Keep rooms.occupied + rooms.status in sync when a student is allocated
CREATE TRIGGER trg_allocation_after_insert
AFTER INSERT ON room_allocations
FOR EACH ROW
BEGIN
    IF NEW.status = 'active' THEN
        -- status is assigned BEFORE occupied so it reads the OLD count
        UPDATE rooms
        SET status   = IF(status = 'Maintenance', 'Maintenance',
                          IF(occupied + 1 >= capacity, 'Full', 'Available')),
            occupied = occupied + 1
        WHERE id = NEW.room_id;
    END IF;
END$$

-- 2) Free the bed when an allocation row is removed
CREATE TRIGGER trg_allocation_after_delete
AFTER DELETE ON room_allocations
FOR EACH ROW
BEGIN
    IF OLD.status = 'active' THEN
        UPDATE rooms
        SET status   = IF(status = 'Maintenance', 'Maintenance',
                          IF(occupied - 1 >= capacity, 'Full', 'Available')),
            occupied = GREATEST(occupied - 1, 0)
        WHERE id = OLD.room_id;
    END IF;
END$$

-- 3) Handle allocation status flips (active <-> inactive)
CREATE TRIGGER trg_allocation_after_update
AFTER UPDATE ON room_allocations
FOR EACH ROW
BEGIN
    IF OLD.status = 'active' AND NEW.status = 'inactive' THEN
        UPDATE rooms
        SET status   = IF(status = 'Maintenance', 'Maintenance',
                          IF(occupied - 1 >= capacity, 'Full', 'Available')),
            occupied = GREATEST(occupied - 1, 0)
        WHERE id = NEW.room_id;
    ELSEIF OLD.status = 'inactive' AND NEW.status = 'active' THEN
        UPDATE rooms
        SET status   = IF(status = 'Maintenance', 'Maintenance',
                          IF(occupied + 1 >= capacity, 'Full', 'Available')),
            occupied = occupied + 1
        WHERE id = NEW.room_id;
    END IF;
END$$

-- 4) A fee written with a past due date is overdue the moment it exists
CREATE TRIGGER trg_payments_before_insert
BEFORE INSERT ON payments
FOR EACH ROW
BEGIN
    IF NEW.status = 'pending' AND NEW.due_date < CURDATE() THEN
        SET NEW.status = 'overdue';
    END IF;
END$$

CREATE TRIGGER trg_payments_before_update
BEFORE UPDATE ON payments
FOR EACH ROW
BEGIN
    IF NEW.status = 'pending' AND NEW.due_date < CURDATE() THEN
        SET NEW.status = 'overdue';
    END IF;
END$$

-- ============================================================
-- STORED PROCEDURE - assign a fee due to a student
-- ============================================================
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

-- ============================================================
-- EVENT - auto-mark overdue fees every hour
-- (requires event_scheduler, enabled in docker-compose.yml)
-- ============================================================
CREATE EVENT IF NOT EXISTS ev_mark_overdue_fees
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO
    UPDATE payments
    SET status = 'overdue'
    WHERE status = 'pending' AND due_date < CURDATE()$$

-- Flag visitors still inside after 12 hours. Change the INTERVAL to tune the threshold.
CREATE EVENT IF NOT EXISTS ev_flag_long_visits
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO UPDATE visitors SET flagged=1 WHERE status='Inside' AND flagged=0 AND entry_time < DATE_SUB(NOW(), INTERVAL 12 HOUR)$$

DELIMITER ;

-- ============================================================
-- VIEWS
-- ============================================================

-- Per-student dues summary (used by admin reports)
CREATE OR REPLACE VIEW student_dues_view AS
SELECT
    u.id                                                    AS student_id,
    u.full_name                                             AS student_name,
    u.email                                                 AS email,
    COALESCE(SUM(CASE WHEN p.status = 'pending'  THEN p.amount END), 0) AS pending_amount,
    COALESCE(SUM(CASE WHEN p.status = 'overdue'  THEN p.amount END), 0) AS overdue_amount,
    COALESCE(SUM(CASE WHEN p.verification_status = 'Approved' THEN p.amount END), 0) AS paid_amount,
    COALESCE(SUM(p.verification_status = 'Pending Verification'), 0)    AS pending_verification_count,
    COUNT(p.id)                                             AS total_fees
FROM users u
LEFT JOIN payments p ON p.student_id = u.id
WHERE u.role = 'student'
GROUP BY u.id, u.full_name, u.email;

-- Room occupancy overview (used by admin reports)
CREATE OR REPLACE VIEW room_occupancy_view AS
SELECT
    r.id                                                     AS room_id,
    r.room_number,
    r.room_type,
    r.capacity,
    r.occupied,
    ROUND(r.occupied * 100.0 / NULLIF(r.capacity, 0), 1)     AS occupancy_pct,
    r.status,
    GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ') AS occupants
FROM rooms r
LEFT JOIN room_allocations ra
       ON ra.room_id = r.id AND ra.status = 'active'
LEFT JOIN users u ON u.id = ra.user_id
GROUP BY r.id, r.room_number, r.room_type, r.capacity, r.occupied, r.status;
