-- Adds the leave register (leave, tours, training, late-arrival permission).
-- Works on MySQL and MariaDB. Run once on the VM:
--     sudo mysql < database/migrations/003_leaves.sql
-- Safe to run twice.

USE staff_attendance;

CREATE TABLE IF NOT EXISTS leaves (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  leave_type   VARCHAR(30)  NOT NULL,
  start_date   DATE NOT NULL,
  end_date     DATE NOT NULL,
  part         ENUM('full','first_half','second_half') NOT NULL DEFAULT 'full',
  until_time   TIME NULL,
  reason       VARCHAR(255) NOT NULL DEFAULT '',
  source       ENUM('manual','csv','api') NOT NULL DEFAULT 'manual',
  external_ref VARCHAR(60) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_source_ref (source, external_ref),
  INDEX idx_user_dates (user_id, start_date, end_date),
  INDEX idx_dates (start_date, end_date),
  CONSTRAINT fk_leave_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
