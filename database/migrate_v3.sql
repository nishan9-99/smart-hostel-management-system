-- One-time upgrade for existing v2.1 database volumes only.
-- Do not run on a fresh v3/v3.1 installation or an already upgraded volume.
-- Select the smart_hostel database first (phpMyAdmin or mysql command in README).
ALTER TABLE users
  ADD COLUMN google_id VARCHAR(255) DEFAULT NULL UNIQUE,
  ADD COLUMN auth_provider ENUM('local','google') NOT NULL DEFAULT 'local';
