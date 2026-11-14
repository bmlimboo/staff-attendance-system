-- Upgrade for databases created before designations and department heads existed.
-- Run once on the VM (MariaDB):
--     sudo mysql < database/migrations/002_designation_and_dept_heads.sql
-- Safe to run twice.

USE staff_attendance;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS designation VARCHAR(100) NOT NULL DEFAULT '' AFTER staff_type;

ALTER TABLE users
  MODIFY role ENUM('sysadmin','admin_officer','admin_assistant','president','hod','section_head','dean','daa','staff')
  NOT NULL DEFAULT 'staff';

CREATE TABLE IF NOT EXISTS department_access (
  user_id       INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, department_id),
  CONSTRAINT fk_da_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_da_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;
