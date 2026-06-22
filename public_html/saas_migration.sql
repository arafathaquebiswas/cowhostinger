-- ============================================================
-- AB IT SaaS Migration — Run after initial schema.sql
-- Safe to run multiple times (IF NOT EXISTS / ON DUPLICATE KEY)
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. Extend plans table with per-resource limits & feature flags ─────────────

ALTER TABLE `plans`
  ADD COLUMN IF NOT EXISTS `workers_limit`   INT UNSIGNED DEFAULT NULL  COMMENT 'NULL=unlimited',
  ADD COLUMN IF NOT EXISTS `equipment_limit` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `feed_limit`      INT UNSIGNED DEFAULT NULL  COMMENT 'max feed inventory items',
  ADD COLUMN IF NOT EXISTS `medicine_limit`  INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `diagnosis_limit` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `can_finance`     TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_reports`     TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_milk_analytics` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `billing_days`    INT UNSIGNED DEFAULT NULL  COMMENT 'subscription duration in days; NULL=forever';

-- ── 2. Seed / update default plans ───────────────────────────────────────────
--     Plan 1 = Free (trial)
--     Plan 2 = Starter (renamed from Basic in migration 010)
--     Plan 3 = Pro
--     Plan 4 = Enterprise

INSERT INTO `plans`
  (id, name, price_monthly, is_active,
   cows_limit, workers_limit, equipment_limit, feed_limit, medicine_limit, diagnosis_limit, users_limit,
   can_export, can_analytics, can_finance, can_reports, can_milk_analytics, billing_days)
VALUES
  (1, 'Free',       0,    1,  5,  2,  5,  5,  5,  10, 2,  0, 0, 0, 0, 0, NULL),
  (2, 'Basic',      499,  1,  30, 10, 20, 20, 20, 100, 5,  0, 1, 1, 1, 0, 30),
  (3, 'Pro',        999,  1,  NULL,NULL,NULL,NULL,NULL,NULL,20, 1, 1, 1, 1, 1, 30),
  (4, 'Enterprise', 2499, 1,  NULL,NULL,NULL,NULL,NULL,NULL,100,1, 1, 1, 1, 1, 365)
ON DUPLICATE KEY UPDATE
  workers_limit      = VALUES(workers_limit),
  equipment_limit    = VALUES(equipment_limit),
  feed_limit         = VALUES(feed_limit),
  medicine_limit     = VALUES(medicine_limit),
  diagnosis_limit    = VALUES(diagnosis_limit),
  can_finance        = VALUES(can_finance),
  can_reports        = VALUES(can_reports),
  can_milk_analytics = VALUES(can_milk_analytics),
  billing_days       = VALUES(billing_days);

-- ── 3. Extend subscriptions with grace period ─────────────────────────────────

ALTER TABLE `subscriptions`
  ADD COLUMN IF NOT EXISTS `grace_end_date` DATE DEFAULT NULL AFTER `end_date`;

-- Add status index if missing
ALTER TABLE `subscriptions` ADD INDEX IF NOT EXISTS `idx_status` (`status`);
ALTER TABLE `subscriptions` ADD INDEX IF NOT EXISTS `idx_end_date` (`end_date`);

-- ── 4. Payments table (future-ready: bKash / Nagad / Rocket) ─────────────────

CREATE TABLE IF NOT EXISTS `payments` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `farm_id`         INT UNSIGNED  NOT NULL,
  `plan_id`         INT UNSIGNED  NOT NULL,
  `amount`          DECIMAL(10,2) NOT NULL,
  `currency`        VARCHAR(10)   NOT NULL DEFAULT 'BDT',
  `method`          ENUM('bkash','nagad','rocket','bank','manual') NOT NULL DEFAULT 'manual',
  `transaction_ref` VARCHAR(100)  DEFAULT NULL,
  `status`          ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `months`          INT UNSIGNED  NOT NULL DEFAULT 1,
  `paid_at`         DATETIME      DEFAULT NULL,
  `recorded_by`     INT UNSIGNED  DEFAULT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_farm_id` (`farm_id`),
  KEY `idx_status`  (`status`),
  KEY `idx_paid_at` (`paid_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Platform settings (AB IT CEO config) ───────────────────────────────────

CREATE TABLE IF NOT EXISTS `platform_settings` (
  `key`        VARCHAR(100) NOT NULL,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `platform_settings` (`key`, `value`) VALUES
  ('company_name',       'AB IT'),
  ('support_phone',      '+880-XXX-XXXXXX'),
  ('support_email',      'support@abit.com.bd'),
  ('grace_period_days',  '5'),
  ('watermark_text',     'AB IT'),
  ('trial_days',         '14')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- ── 6. Impersonation session log (CEO security) ───────────────────────────────

CREATE TABLE IF NOT EXISTS `impersonation_log` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `superadmin_id`   INT UNSIGNED NOT NULL,
  `target_farm_id`  INT UNSIGNED NOT NULL,
  `started_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at`        DATETIME     DEFAULT NULL,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_superadmin` (`superadmin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
