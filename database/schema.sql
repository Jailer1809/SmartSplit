CREATE DATABASE IF NOT EXISTS explainable_budget
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE explainable_budget;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer', 'bank', 'admin') NOT NULL DEFAULT 'customer',
  customer_reference_id CHAR(19) NULL UNIQUE,
  is_approved BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role_approval (role, is_approved)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_verification_details (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  role ENUM('normal_user', 'bank_employee') NOT NULL,
  pan_id_hash CHAR(64) NULL UNIQUE,
  pan_id_masked CHAR(10) NULL,
  bank_name VARCHAR(120) NULL,
  bank_employee_id VARCHAR(32) NULL,
  verification_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bank_employee_identity (bank_name, bank_employee_id),
  CONSTRAINT fk_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_verification_role_fields CHECK (
    (role = 'normal_user' AND pan_id_hash IS NOT NULL AND pan_id_masked IS NOT NULL
      AND bank_name IS NULL AND bank_employee_id IS NULL)
    OR
    (role = 'bank_employee' AND pan_id_hash IS NULL AND pan_id_masked IS NULL
      AND bank_name IS NOT NULL AND bank_employee_id IS NOT NULL)
  )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS finance_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  category ENUM('income', 'savings', 'emi', 'rent', 'other') NOT NULL,
  amount DECIMAL(13,2) NOT NULL,
  month CHAR(7) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_finance_user_category_month (user_id, category, month),
  CONSTRAINT fk_finance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loan_commitments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  loan_label VARCHAR(100) NOT NULL,
  monthly_emi DECIMAL(13,2) NOT NULL,
  status ENUM('active', 'closed') NOT NULL DEFAULT 'active',
  amount_paid DECIMAL(13,2) NULL,
  paid_on_time BOOLEAN NULL,
  is_demo BOOLEAN NOT NULL DEFAULT FALSE,
  demo_key VARCHAR(40) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_loan_commitments_user_status (user_id, status),
  UNIQUE KEY uq_loan_commitments_demo (user_id, demo_key),
  CONSTRAINT fk_loan_commitments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS repayment_history (
  user_id BIGINT UNSIGNED NOT NULL,
  month CHAR(7) NOT NULL,
  on_time_payments TINYINT UNSIGNED NOT NULL DEFAULT 0,
  missed_payments TINYINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, month),
  CONSTRAINT fk_repayment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS spending_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  category ENUM('savings', 'emi', 'rent', 'other') NOT NULL,
  limit_amount DECIMAL(13,2) NOT NULL,
  UNIQUE KEY uq_limits_user_category (user_id, category),
  CONSTRAINT fk_limits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS risk_scores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  score DECIMAL(6,2) NOT NULL,
  risk_category ENUM('Low', 'Medium', 'High') NOT NULL,
  explanation_json JSON NOT NULL,
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_risk_user_computed (user_id, computed_at),
  CONSTRAINT fk_risk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blockchain_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  block_index BIGINT UNSIGNED NOT NULL UNIQUE,
  timestamp VARCHAR(40) NOT NULL,
  data_hash CHAR(64) NOT NULL,
  previous_hash CHAR(64) NOT NULL,
  record_ref_id VARCHAR(100) NOT NULL,
  block_hash CHAR(64) NOT NULL,
  record_type VARCHAR(40) NOT NULL DEFAULT 'risk_score',
  INDEX idx_ledger_record_ref (record_ref_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  message VARCHAR(500) NOT NULL,
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  category ENUM('savings', 'emi', 'rent', 'other') NULL,
  month CHAR(7) NULL,
  threshold_percent TINYINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notification_threshold (user_id, category, month, threshold_percent),
  INDEX idx_notifications_user_read (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bank_consents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  reference_id CHAR(32) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_consent_active_ref (reference_id, expires_at, revoked_at),
  CONSTRAINT fk_consent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subject_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_subject_time (subject_hash, created_at),
  INDEX idx_login_ip_time (ip_hash, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  event VARCHAR(100) NOT NULL,
  details_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_logs_created (created_at),
  CONSTRAINT fk_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
