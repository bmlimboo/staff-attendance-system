-- CNR Staff Attendance System - database schema
-- Import with:  mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS staff_attendance
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE staff_attendance;

CREATE TABLE IF NOT EXISTS departments (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  code VARCHAR(20)  NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id             VARCHAR(30)  NOT NULL UNIQUE,
  name                 VARCHAR(120) NOT NULL,
  email                VARCHAR(150) NOT NULL UNIQUE,
  password_hash        VARCHAR(255) NOT NULL,
  role ENUM('sysadmin','admin_officer','admin_assistant','president','hod','section_head','dean','daa','staff')
                       NOT NULL DEFAULT 'staff',
  staff_type           ENUM('teaching','non_teaching') NOT NULL DEFAULT 'teaching',
  designation          VARCHAR(100) NOT NULL DEFAULT '',
  department_id        INT UNSIGNED NULL,
  track_attendance     TINYINT(1) NOT NULL DEFAULT 1,
  active               TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_dept FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Extra departments a Head of Department / Section Head / Dean looks after,
-- in addition to the department on their own staff record.
CREATE TABLE IF NOT EXISTS department_access (
  user_id       INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, department_id),
  CONSTRAINT fk_da_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_da_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Leave, tours, training and late-arrival permissions. Kept apart from the daily
-- attendance rows so adding or changing leave later automatically updates every
-- report (nothing needs to be re-entered day by day).
CREATE TABLE IF NOT EXISTS leaves (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  leave_type   VARCHAR(30)  NOT NULL,
  start_date   DATE NOT NULL,
  end_date     DATE NOT NULL,
  part         ENUM('full','first_half','second_half') NOT NULL DEFAULT 'full',
  until_time   TIME NULL,                              -- only for late-arrival permission
  reason       VARCHAR(255) NOT NULL DEFAULT '',
  source       ENUM('manual','csv','api') NOT NULL DEFAULT 'manual',
  external_ref VARCHAR(60) NULL,                       -- id in the academic system / IMS
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_source_ref (source, external_ref),
  INDEX idx_user_dates (user_id, start_date, end_date),
  INDEX idx_dates (start_date, end_date),
  CONSTRAINT fk_leave_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS devices (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NOT NULL UNIQUE,          -- sha256 of the cookie token
  label        VARCHAR(80) NOT NULL,
  device_type  ENUM('laptop','mobile') NOT NULL,
  user_agent   VARCHAR(255) NOT NULL DEFAULT '',
  platform     VARCHAR(60)  NOT NULL DEFAULT '',
  screen       VARCHAR(20)  NOT NULL DEFAULT '',
  status       ENUM('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL,
  decided_at   DATETIME NULL,
  decided_by   INT UNSIGNED NULL,
  last_used_at DATETIME NULL,
  CONSTRAINT fk_dev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_dev_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  att_date     DATE NOT NULL,
  check_in     TIME NULL,
  check_out    TIME NULL,
  status       ENUM('present','late','leave','absent') NOT NULL DEFAULT 'present',
  late_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  device_id    INT UNSIGNED NULL,
  ip_address   VARCHAR(45) NOT NULL DEFAULT '',
  source       ENUM('auto','admin') NOT NULL DEFAULT 'auto',
  remarks      VARCHAR(255) NOT NULL DEFAULT '',
  updated_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_date (user_id, att_date),
  INDEX idx_date (att_date),
  CONSTRAINT fk_att_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS holidays (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_date DATE NOT NULL,
  title        VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_holiday_date (holiday_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(150) NOT NULL,
  ip_address   VARCHAR(45)  NOT NULL,
  attempted_at DATETIME NOT NULL,
  INDEX idx_ident (identifier, attempted_at),
  INDEX idx_ip (ip_address, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(60)  NOT NULL,
  details    VARCHAR(500) NOT NULL DEFAULT '',
  ip_address VARCHAR(45)  NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Defaults ---------------------------------------------------------------
INSERT IGNORE INTO settings (k, v) VALUES
  ('allowed_networks',    '10.0.0.0/8\n172.16.0.0/12\n192.168.0.0/16'),
  ('teaching_start',      '08:00'),
  ('teaching_end',        '17:30'),
  ('nonteaching_start',   '08:30'),
  ('nonteaching_end',     '16:30'),
  ('lunch_start',         '12:00'),
  ('lunch_end',           '13:00'),
  ('grace_minutes',       '0'),
  ('max_devices',         '2'),
  ('working_days',        '1,2,3,4,5');

-- Code ADMIN is used by bin/create_admin.php, so keep it.
INSERT IGNORE INTO departments (name, code) VALUES
  ('Administration / HR',                'ADMIN'),
  ('Agriculture',                        'AGR'),
  ('Forestry',                           'FOR'),
  ('Food Science & Technology',          'FST'),
  ('Environment and Climate Studies',    'ECS'),
  ('Animal Science',                     'ANS'),
  ('Sustainable Development',            'SD'),
  ('Library',                            'LIB'),
  ('ICT',                                'ICT'),
  ('Laboratory',                         'LAB'),
  ('Exam Cell',                          'EXAM'),
  ('Student Services',                   'SS'),
  ('DRIL',                               'DRIL'),
  ('Farm',                               'FARM'),
  ('Estate and Maintenance',             'EST');
-- The Engineering Cell has one person whose attendance HR looks after,
-- so he is placed in "Administration / HR" (no separate department).
