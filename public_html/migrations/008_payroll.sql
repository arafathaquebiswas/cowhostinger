-- ============================================================
-- Migration 008: Payroll Management Module
-- Run after 007_schema_hardening.sql
-- ============================================================

-- Monthly payroll batches (one per farm per period)
CREATE TABLE IF NOT EXISTS `payroll_batches` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `farm_id`       INT UNSIGNED  NOT NULL,
  `period_label`  VARCHAR(30)   NOT NULL,
  `period_from`   DATE          NOT NULL,
  `period_to`     DATE          NOT NULL,
  `total_workers` INT UNSIGNED  NOT NULL DEFAULT 0,
  `total_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`        ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
  `approved_by`   INT UNSIGNED  DEFAULT NULL,
  `approved_at`   TIMESTAMP     NULL DEFAULT NULL,
  `notes`         TEXT          DEFAULT NULL,
  `created_by`    INT UNSIGNED  NOT NULL,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_farm_period` (`farm_id`, `period_from`),
  KEY `idx_pb_farm_id`  (`farm_id`),
  KEY `idx_pb_status`   (`status`),
  KEY `idx_pb_period`   (`period_from`),
  CONSTRAINT `fk_pb_farm`     FOREIGN KEY (`farm_id`)     REFERENCES `farms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pb_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pb_creator`  FOREIGN KEY (`created_by`)  REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-worker payroll lines within a batch
CREATE TABLE IF NOT EXISTS `payroll_records` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `farm_id`        INT UNSIGNED     NOT NULL,
  `batch_id`       INT UNSIGNED     NOT NULL,
  `worker_id`      INT UNSIGNED     NOT NULL,
  `basic_salary`   DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `working_days`   TINYINT UNSIGNED NOT NULL DEFAULT 30,
  `present_days`   TINYINT UNSIGNED NOT NULL DEFAULT 30,
  `overtime_pay`   DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `bonuses`        DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `deductions`     DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `net_salary`     DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','bank','bkash','nagad','rocket') NOT NULL DEFAULT 'cash',
  `payment_date`   DATE             DEFAULT NULL,
  `status`         ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  `notes`          TEXT             DEFAULT NULL,
  `created_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch_worker` (`batch_id`, `worker_id`),
  KEY `idx_pr_farm_id`   (`farm_id`),
  KEY `idx_pr_batch_id`  (`batch_id`),
  KEY `idx_pr_worker_id` (`worker_id`),
  KEY `idx_pr_status`    (`status`),
  CONSTRAINT `fk_pr_farm`   FOREIGN KEY (`farm_id`)  REFERENCES `farms`           (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pr_batch`  FOREIGN KEY (`batch_id`) REFERENCES `payroll_batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pr_worker` FOREIGN KEY (`worker_id`)REFERENCES `workers`          (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
