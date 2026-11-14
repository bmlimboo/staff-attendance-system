-- Upgrade for databases created before designations and department heads existed.
-- Works on both MySQL and MariaDB. Run once on the VM:
--     sudo mysql < database/migrations/002_designation_and_dept_heads.sql
-- Safe to run twice.

USE staff_attendance;

-- Add the designation column only if it is not there yet.
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'designation');
SET @ddl := IF(@has_col = 0,
               'ALTER TABLE users ADD COLUMN designation VARCHAR(100) NOT NULL DEFAULT '''' AFTER staff_type',
               'SELECT ''designation column already exists''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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
