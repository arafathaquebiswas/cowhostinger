-- ============================================================
-- Migration 007: Schema Hardening & Multi-tenancy Enforcement
-- Version: 1.0  |  Date: 2026-06-21
-- Safe to run multiple times — uses IF NOT EXISTS / IF EXISTS
-- Run AFTER schema.sql and saas_migration.sql
-- ============================================================
--
-- WHAT THIS DOES
-- 1. Adds farm_id to every domain table that was missing it
--    (schema.sql pre-dates multi-farm support)
-- 2. Adds milk_value and name columns used by the finance engine
-- 3. Adds users.farm_id, users.is_owner, users.phone
-- 4. Adds module_settings.farm_id for per-farm feature toggling
-- 5. Adds covering indexes for the most frequent query patterns
-- 6. Cleans up missing completed_date index on maintenance_logs
--
-- ROLLBACK SAFETY
-- Every ADD COLUMN uses IF NOT EXISTS.
-- No existing columns are renamed or dropped.
-- No data is modified.
-- Run on a test copy first; takes ~1 s on a <50 k row database.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ──────────────────────────────────────────────────────────────
-- 1. USERS — add multi-tenancy columns
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `farm_id`  INT UNSIGNED DEFAULT NULL AFTER `role`,
    ADD COLUMN IF NOT EXISTS `is_owner` TINYINT(1) NOT NULL DEFAULT 0 AFTER `farm_id`,
    ADD COLUMN IF NOT EXISTS `phone`    VARCHAR(30)  DEFAULT NULL AFTER `email`;

ALTER TABLE `users`
    ADD INDEX IF NOT EXISTS `idx_farm_id` (`farm_id`);

-- ──────────────────────────────────────────────────────────────
-- 2. COWS — add farm_id and display name
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `cows`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `name`    VARCHAR(100)  DEFAULT NULL AFTER `tag_number`;

ALTER TABLE `cows`
    ADD INDEX IF NOT EXISTS `idx_farm_id`       (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_status`   (`farm_id`, `status`),
    ADD INDEX IF NOT EXISTS `idx_farm_pregnant` (`farm_id`, `is_pregnant`);

-- ──────────────────────────────────────────────────────────────
-- 3. MILK RECORDS — add farm_id and pre-computed milk_value
--    milk_value stores revenue at time of recording (price-locked)
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `milk_records`
    ADD COLUMN IF NOT EXISTS `farm_id`    INT UNSIGNED   DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `milk_value` DECIMAL(12,2)  DEFAULT NULL
        COMMENT 'Revenue = liters × price_per_liter at time of recording (historical lock)';

ALTER TABLE `milk_records`
    ADD INDEX IF NOT EXISTS `idx_farm_id`       (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_date`     (`farm_id`, `recorded_at`),
    ADD INDEX IF NOT EXISTS `idx_farm_cow_date` (`farm_id`, `cow_id`, `recorded_at`);

-- ──────────────────────────────────────────────────────────────
-- 4. MILK PRICE HISTORY — per-farm pricing
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `milk_price_history`
    ADD COLUMN IF NOT EXISTS `farm_id`    INT UNSIGNED DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `created_by` INT UNSIGNED DEFAULT NULL AFTER `effective_date`;

ALTER TABLE `milk_price_history`
    ADD INDEX IF NOT EXISTS `idx_farm_date` (`farm_id`, `effective_date`);

-- ──────────────────────────────────────────────────────────────
-- 5. COW SALES — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `cow_sales`
    ADD COLUMN IF NOT EXISTS `farm_id`     INT UNSIGNED DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `seller_name` VARCHAR(150) DEFAULT NULL AFTER `buyer_name`,
    ADD COLUMN IF NOT EXISTS `recorded_by` INT UNSIGNED DEFAULT NULL;

ALTER TABLE `cow_sales`
    ADD INDEX IF NOT EXISTS `idx_farm_id`   (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_date` (`farm_id`, `sale_date`);

-- ──────────────────────────────────────────────────────────────
-- 6. MEAT SALES — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `meat_sales`
    ADD COLUMN IF NOT EXISTS `farm_id`     INT UNSIGNED DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `recorded_by` INT UNSIGNED DEFAULT NULL;

ALTER TABLE `meat_sales`
    ADD INDEX IF NOT EXISTS `idx_farm_id`   (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_date` (`farm_id`, `sale_date`);

-- ──────────────────────────────────────────────────────────────
-- 7. WORKERS — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `workers`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `workers`
    ADD INDEX IF NOT EXISTS `idx_farm_id`       (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_hire`     (`farm_id`, `hire_date`),
    ADD INDEX IF NOT EXISTS `idx_termination`   (`termination_date`);

-- ──────────────────────────────────────────────────────────────
-- 8. WORKER TASKS — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `worker_tasks`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `worker_tasks`
    ADD INDEX IF NOT EXISTS `idx_farm_id` (`farm_id`);

-- ──────────────────────────────────────────────────────────────
-- 9. FARM AREAS — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `farm_areas`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `farm_areas`
    ADD INDEX IF NOT EXISTS `idx_farm_id` (`farm_id`);

-- ──────────────────────────────────────────────────────────────
-- 10. EQUIPMENT — farm scope + purchase_cost
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `equipment`
    ADD COLUMN IF NOT EXISTS `farm_id`       INT UNSIGNED   DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `purchase_cost` DECIMAL(12,2)  DEFAULT NULL AFTER `purchase_date`,
    ADD COLUMN IF NOT EXISTS `sell_price`    DECIMAL(12,2)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `sold_at`       DATE           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `created_by`    INT UNSIGNED   DEFAULT NULL;

ALTER TABLE `equipment`
    ADD INDEX IF NOT EXISTS `idx_farm_id`     (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_status` (`farm_id`, `status`);

-- ──────────────────────────────────────────────────────────────
-- 11. FEED INVENTORY — farm scope + cost tracking
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `feed_inventory`
    ADD COLUMN IF NOT EXISTS `farm_id`       INT UNSIGNED   DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `cost_per_unit` DECIMAL(10,2)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `created_by`    INT UNSIGNED   DEFAULT NULL;

ALTER TABLE `feed_inventory`
    ADD INDEX IF NOT EXISTS `idx_farm_id`    (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_qty`   (`farm_id`, `quantity`);

-- ──────────────────────────────────────────────────────────────
-- 12. MEDICINE INVENTORY — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `medicine_inventory`
    ADD COLUMN IF NOT EXISTS `farm_id`       INT UNSIGNED   DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `cost_per_unit` DECIMAL(10,2)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `created_by`    INT UNSIGNED   DEFAULT NULL;

ALTER TABLE `medicine_inventory`
    ADD INDEX IF NOT EXISTS `idx_farm_id`     (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_expiry` (`farm_id`, `expiry_date`);

-- ──────────────────────────────────────────────────────────────
-- 13. TREATMENTS — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `treatments`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `treatments`
    ADD INDEX IF NOT EXISTS `idx_farm_id`    (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_date`  (`farm_id`, `treatment_date`),
    ADD INDEX IF NOT EXISTS `idx_farm_cow`   (`farm_id`, `cow_id`);

-- ──────────────────────────────────────────────────────────────
-- 14. MAINTENANCE LOGS — farm scope + completed_date index
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `maintenance_logs`
    ADD COLUMN IF NOT EXISTS `farm_id`   INT UNSIGNED DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `status`    ENUM('pending','in_progress','completed','cancelled')
                                          NOT NULL DEFAULT 'pending' AFTER `cost`;

ALTER TABLE `maintenance_logs`
    ADD INDEX IF NOT EXISTS `idx_farm_id`         (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_completed`  (`farm_id`, `completed_date`),
    ADD INDEX IF NOT EXISTS `idx_completed_date`  (`completed_date`);

-- ──────────────────────────────────────────────────────────────
-- 15. AREA PURCHASES — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `area_purchases`
    ADD COLUMN IF NOT EXISTS `farm_id`   INT UNSIGNED DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `purchased_by` INT UNSIGNED DEFAULT NULL;

ALTER TABLE `area_purchases`
    ADD INDEX IF NOT EXISTS `idx_farm_id`   (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_date` (`farm_id`, `purchase_date`);

-- ──────────────────────────────────────────────────────────────
-- 16. FINANCE TRANSACTIONS — farm scope (CRITICAL for multi-tenant)
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `finance_transactions`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `finance_transactions`
    ADD INDEX IF NOT EXISTS `idx_farm_id`        (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_date`      (`farm_id`, `transaction_date`),
    ADD INDEX IF NOT EXISTS `idx_farm_type`      (`farm_id`, `type`),
    ADD INDEX IF NOT EXISTS `idx_farm_type_date` (`farm_id`, `type`, `transaction_date`);

-- ──────────────────────────────────────────────────────────────
-- 17. ALERTS — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `alerts`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `alerts`
    ADD INDEX IF NOT EXISTS `idx_farm_id`      (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_read`    (`farm_id`, `is_read`),
    ADD INDEX IF NOT EXISTS `idx_farm_sev`     (`farm_id`, `severity`);

-- ──────────────────────────────────────────────────────────────
-- 18. MODULE SETTINGS — add farm_id for per-farm feature toggling
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `module_settings`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

-- Drop old unique key on module_name alone, add compound key
ALTER TABLE `module_settings`
    DROP INDEX IF EXISTS `uk_module_name`;

ALTER TABLE `module_settings`
    ADD UNIQUE KEY IF NOT EXISTS `uk_farm_module` (`farm_id`, `module_name`);

-- ──────────────────────────────────────────────────────────────
-- 19. BREEDING RECORDS — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `breeding_records`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `breeding_records`
    ADD INDEX IF NOT EXISTS `idx_farm_id` (`farm_id`);

-- ──────────────────────────────────────────────────────────────
-- 20. CALF RECORDS — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `calf_records`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `calf_records`
    ADD INDEX IF NOT EXISTS `idx_farm_id` (`farm_id`);

-- ──────────────────────────────────────────────────────────────
-- 21. COW WEIGHT LOGS — farm scope (avoid expensive JOIN via cows)
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `cow_weight_logs`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `cow_weight_logs`
    ADD INDEX IF NOT EXISTS `idx_farm_id`   (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_date` (`farm_id`, `recorded_at`);

-- ──────────────────────────────────────────────────────────────
-- 22. DIAGNOSIS RECORDS — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `diagnosis_records`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `diagnosis_records`
    ADD INDEX IF NOT EXISTS `idx_farm_id` (`farm_id`);

-- ──────────────────────────────────────────────────────────────
-- 23. COW SYMPTOMS — farm scope
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `cow_symptoms`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

ALTER TABLE `cow_symptoms`
    ADD INDEX IF NOT EXISTS `idx_farm_id` (`farm_id`);

-- ──────────────────────────────────────────────────────────────
-- 24. AUDIT LOG — add farm_id for per-farm audit filtering
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `audit_log`
    ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`;

ALTER TABLE `audit_log`
    ADD INDEX IF NOT EXISTS `idx_farm_id`   (`farm_id`),
    ADD INDEX IF NOT EXISTS `idx_farm_date` (`farm_id`, `created_at`);

-- ──────────────────────────────────────────────────────────────
-- 25. LOGIN ATTEMPTS — ensure table exists
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address`   VARCHAR(45)  NOT NULL,
    `identifier`   VARCHAR(255) NOT NULL COMMENT 'email or farm_code:phone',
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ip`         (`ip_address`, `attempted_at`),
    KEY `idx_identifier` (`identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 26. ACTIVITY LOG — ensure table exists
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED DEFAULT NULL,
    `farm_id`    INT UNSIGNED DEFAULT NULL,
    `action`     VARCHAR(100) NOT NULL,
    `context`    JSON         DEFAULT NULL,
    `ip_address` VARCHAR(45)  DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id`    (`user_id`),
    KEY `idx_farm_id`    (`farm_id`),
    KEY `idx_action`     (`action`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 27. CEO GRANTS — ensure table exists (used by ceo/audit.php)
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ceo_grants` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `farm_id`      INT UNSIGNED NOT NULL,
    `action`       VARCHAR(100) NOT NULL,
    `amount`       DECIMAL(12,2) DEFAULT NULL,
    `reason`       TEXT          DEFAULT NULL,
    `granted_by`   INT UNSIGNED  NOT NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_farm_id` (`farm_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 28. SUPPORT TABLES — ensure they exist
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `farm_id`     INT UNSIGNED NOT NULL,
    `subject`     VARCHAR(255) NOT NULL,
    `description` TEXT         DEFAULT NULL,
    `priority`    ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `status`      ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    `assigned_to` INT UNSIGNED DEFAULT NULL,
    `created_by`  INT UNSIGNED DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_farm_id`    (`farm_id`),
    KEY `idx_status`     (`status`),
    KEY `idx_priority`   (`priority`),
    KEY `idx_assigned`   (`assigned_to`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_ticket_messages` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id`   INT UNSIGNED NOT NULL,
    `sender_id`   INT UNSIGNED NOT NULL,
    `message`     TEXT         NOT NULL,
    `is_internal` TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ticket_id`  (`ticket_id`),
    KEY `idx_sender_id`  (`sender_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- NOTES FOR HOSTINGER / PRODUCTION
-- ============================================================
-- 1. Run this via phpMyAdmin → Import → this file
-- 2. All existing data is preserved (ADD COLUMN IF NOT EXISTS)
-- 3. New farm_id columns default to NULL — existing rows are unaffected
-- 4. After running: verify with: SHOW COLUMNS FROM cows;
-- 5. No foreign key constraints added to avoid FK conflicts on Hostinger
-- ============================================================
