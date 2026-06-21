-- ============================================================
-- Cow Management & Diagnosis System
-- MySQL/MariaDB Database Schema — Version 1.0
-- Import via phpMyAdmin or: mysql -u user -p dbname < schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. USERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('superadmin','support_staff','admin','manager','accountant','veterinarian','worker') NOT NULL,
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email`  (`email`),
  KEY `idx_role`   (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. FARM AREAS  (created early — referenced by maintenance_logs)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `farm_areas` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150) NOT NULL,
  `type`       ENUM('barn','storage','milking_shed','medical','office','other') NOT NULL DEFAULT 'other',
  `capacity`   INT UNSIGNED DEFAULT NULL,
  `notes`      TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. EQUIPMENT  (created early — referenced by maintenance_logs)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `equipment` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                  VARCHAR(150) NOT NULL,
  `purchase_date`         DATE DEFAULT NULL,
  `status`                ENUM('operational','maintenance','damaged') NOT NULL DEFAULT 'operational',
  `lifespan_months`       INT UNSIGNED DEFAULT NULL,
  `last_maintenance_date` DATE DEFAULT NULL,
  `photo_url`             VARCHAR(255) DEFAULT NULL,
  `notes`                 TEXT DEFAULT NULL,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. COWS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cows` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `tag_number`     VARCHAR(50)   NOT NULL,
  `breed`          VARCHAR(100)  NOT NULL,
  `birth_date`     DATE DEFAULT NULL,
  `purchase_price` DECIMAL(12,2) DEFAULT NULL,
  `purchase_date`  DATE DEFAULT NULL,
  `current_weight` DECIMAL(8,2)  DEFAULT NULL,
  `health_status`  VARCHAR(100)  NOT NULL DEFAULT 'healthy',
  `is_pregnant`    TINYINT(1)    NOT NULL DEFAULT 0,
  `status`         ENUM('active','pregnant','lactating','dry','sick','quarantine','ready_for_sale','sold','deceased') NOT NULL DEFAULT 'active',
  `photo_url`      VARCHAR(255)  DEFAULT NULL,
  `notes`          TEXT DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tag_number` (`tag_number`),
  KEY `idx_status`      (`status`),
  KEY `idx_is_pregnant` (`is_pregnant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. COW WEIGHT LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cow_weight_logs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cow_id`      INT UNSIGNED NOT NULL,
  `weight`      DECIMAL(8,2) NOT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_by` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cow_id`      (`cow_id`),
  KEY `idx_recorded_at` (`recorded_at`),
  CONSTRAINT `fk_cwl_cow`  FOREIGN KEY (`cow_id`)      REFERENCES `cows`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cwl_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. MILK RECORDS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `milk_records` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cow_id`             INT UNSIGNED NOT NULL,
  `liters`             DECIMAL(8,2) NOT NULL,
  `fat_percentage`     DECIMAL(5,2) DEFAULT NULL,
  `contamination_flag` TINYINT(1)   NOT NULL DEFAULT 0,
  `recorded_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_by`        INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cow_id`      (`cow_id`),
  KEY `idx_recorded_at` (`recorded_at`),
  CONSTRAINT `fk_mr_cow`  FOREIGN KEY (`cow_id`)      REFERENCES `cows`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mr_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. MILK PRICE HISTORY
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `milk_price_history` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `price_per_liter` DECIMAL(10,2) NOT NULL,
  `effective_date`  DATE NOT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_effective_date` (`effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. COW SALES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cow_sales` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `cow_id`      INT UNSIGNED  NOT NULL,
  `buyer_name`  VARCHAR(150)  NOT NULL,
  `sale_price`  DECIMAL(12,2) NOT NULL,
  `sale_date`   DATE NOT NULL,
  `profit_loss` DECIMAL(12,2) DEFAULT NULL,
  `approved_by` INT UNSIGNED  DEFAULT NULL,
  `notes`       TEXT DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cow_id`    (`cow_id`),
  KEY `idx_sale_date` (`sale_date`),
  CONSTRAINT `fk_cs_cow`      FOREIGN KEY (`cow_id`)      REFERENCES `cows`  (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cs_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. MEAT SALES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meat_sales` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `cow_id`        INT UNSIGNED  NOT NULL,
  `kg_sold`       DECIMAL(8,2)  NOT NULL,
  `price_per_kg`  DECIMAL(10,2) NOT NULL,
  `total_revenue` DECIMAL(12,2) NOT NULL,
  `event_type`    ENUM('regular','eid','gift') NOT NULL DEFAULT 'regular',
  `sale_date`     DATE NOT NULL,
  `notes`         TEXT DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cow_id`    (`cow_id`),
  KEY `idx_sale_date` (`sale_date`),
  CONSTRAINT `fk_ms_cow` FOREIGN KEY (`cow_id`) REFERENCES `cows` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. WORKERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `workers` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED  NOT NULL,
  `salary`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `hire_date`        DATE NOT NULL,
  `termination_date` DATE DEFAULT NULL,
  `status`           ENUM('active','inactive','terminated') NOT NULL DEFAULT 'active',
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_w_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. WORKER TASKS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `worker_tasks` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `worker_id`     INT UNSIGNED NOT NULL,
  `task_type`     VARCHAR(100) NOT NULL,
  `description`   TEXT DEFAULT NULL,
  `assigned_date` DATE NOT NULL,
  `completed_at`  DATETIME DEFAULT NULL,
  `status`        ENUM('pending','in_progress','completed','overdue') NOT NULL DEFAULT 'pending',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_worker_id`     (`worker_id`),
  KEY `idx_status`        (`status`),
  KEY `idx_assigned_date` (`assigned_date`),
  CONSTRAINT `fk_wt_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. FEED INVENTORY
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `feed_inventory` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `item_name`         VARCHAR(150)  NOT NULL,
  `quantity`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `unit`              VARCHAR(50)   NOT NULL,
  `reorder_threshold` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `last_updated`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_name` (`item_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13. MEDICINE INVENTORY
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `medicine_inventory` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `item_name`         VARCHAR(150)  NOT NULL,
  `quantity`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `unit`              VARCHAR(50)   NOT NULL,
  `expiry_date`       DATE DEFAULT NULL,
  `reorder_threshold` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `last_updated`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expiry_date` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14. TREATMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `treatments` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `cow_id`          INT UNSIGNED  NOT NULL,
  `medicine_id`     INT UNSIGNED  DEFAULT NULL,
  `administered_by` INT UNSIGNED  NOT NULL,
  `dosage`          VARCHAR(100)  DEFAULT NULL,
  `cost`            DECIMAL(10,2) DEFAULT NULL,
  `treatment_date`  DATE NOT NULL,
  `notes`           TEXT DEFAULT NULL,
  `photo_url`       VARCHAR(255)  DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cow_id`         (`cow_id`),
  KEY `idx_treatment_date` (`treatment_date`),
  CONSTRAINT `fk_t_cow`      FOREIGN KEY (`cow_id`)          REFERENCES `cows`               (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_t_medicine` FOREIGN KEY (`medicine_id`)     REFERENCES `medicine_inventory` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_t_user`     FOREIGN KEY (`administered_by`) REFERENCES `users`              (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 15. MAINTENANCE LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `maintenance_logs` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `equipment_id`   INT UNSIGNED  DEFAULT NULL,
  `area_id`        INT UNSIGNED  DEFAULT NULL,
  `description`    TEXT NOT NULL,
  `cost`           DECIMAL(10,2) DEFAULT NULL,
  `scheduled_date` DATE DEFAULT NULL,
  `completed_date` DATE DEFAULT NULL,
  `photo_url`      VARCHAR(255)  DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_equipment_id`   (`equipment_id`),
  KEY `idx_area_id`        (`area_id`),
  KEY `idx_scheduled_date` (`scheduled_date`),
  CONSTRAINT `fk_ml_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment`  (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ml_area`      FOREIGN KEY (`area_id`)      REFERENCES `farm_areas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 16. AREA PURCHASES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `area_purchases` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `area_id`       INT UNSIGNED  NOT NULL,
  `item`          VARCHAR(200)  NOT NULL,
  `cost`          DECIMAL(10,2) NOT NULL,
  `purchase_date` DATE NOT NULL,
  `notes`         TEXT DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_area_id`       (`area_id`),
  KEY `idx_purchase_date` (`purchase_date`),
  CONSTRAINT `fk_ap_area` FOREIGN KEY (`area_id`) REFERENCES `farm_areas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 17. FINANCE TRANSACTIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `finance_transactions` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `type`             ENUM('income','expense') NOT NULL,
  `category`         VARCHAR(100)  NOT NULL,
  `amount`           DECIMAL(12,2) NOT NULL,
  `related_module`   VARCHAR(50)   DEFAULT NULL,
  `reference_id`     INT UNSIGNED  DEFAULT NULL,
  `transaction_date` DATE NOT NULL,
  `recorded_by`      INT UNSIGNED  NOT NULL,
  `approved_by`      INT UNSIGNED  DEFAULT NULL,
  `notes`            TEXT DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type`             (`type`),
  KEY `idx_transaction_date` (`transaction_date`),
  KEY `idx_category`         (`category`),
  CONSTRAINT `fk_ft_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ft_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 18. ALERTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alerts` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`          VARCHAR(100) NOT NULL,
  `severity`      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `message`       TEXT NOT NULL,
  `related_table` VARCHAR(100) DEFAULT NULL,
  `related_id`    INT UNSIGNED DEFAULT NULL,
  `is_read`       TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_severity`   (`severity`),
  KEY `idx_is_read`    (`is_read`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 19. MODULE SETTINGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `module_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_name` VARCHAR(100) NOT NULL,
  `is_enabled`  TINYINT(1)   NOT NULL DEFAULT 1,
  `updated_by`  INT UNSIGNED DEFAULT NULL,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_name` (`module_name`),
  CONSTRAINT `fk_ms_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 20. AUDIT LOG
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(100) NOT NULL,
  `table_name` VARCHAR(100) NOT NULL,
  `record_id`  INT UNSIGNED DEFAULT NULL,
  `old_value`  JSON DEFAULT NULL,
  `new_value`  JSON DEFAULT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`    (`user_id`),
  KEY `idx_table_name` (`table_name`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 21. COW SYMPTOMS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cow_symptoms` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cow_id`           INT UNSIGNED NOT NULL,
  `symptom`          VARCHAR(255) NOT NULL,
  `severity`         ENUM('mild','moderate','severe') NOT NULL DEFAULT 'mild',
  `temperature`      DECIMAL(5,2) DEFAULT NULL,
  `heart_rate`       INT UNSIGNED DEFAULT NULL,
  `appetite_status`  ENUM('normal','reduced','none') DEFAULT NULL,
  `stool_condition`  VARCHAR(100) DEFAULT NULL,
  `milk_color`       VARCHAR(100) DEFAULT NULL,
  `milk_consistency` VARCHAR(100) DEFAULT NULL,
  `blood_in_milk`    TINYINT(1)   NOT NULL DEFAULT 0,
  `notes`            TEXT DEFAULT NULL,
  `recorded_by`      INT UNSIGNED NOT NULL,
  `recorded_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cow_id`      (`cow_id`),
  KEY `idx_recorded_at` (`recorded_at`),
  CONSTRAINT `fk_sym_cow`  FOREIGN KEY (`cow_id`)      REFERENCES `cows`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sym_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 22. DIAGNOSIS RECORDS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `diagnosis_records` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cow_id`             INT UNSIGNED NOT NULL,
  `diagnosis`          TEXT NOT NULL,
  `confidence_level`   ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `recommended_action` TEXT DEFAULT NULL,
  `veterinarian_id`    INT UNSIGNED NOT NULL,
  `photo_url`          VARCHAR(255) DEFAULT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cow_id`          (`cow_id`),
  KEY `idx_veterinarian_id` (`veterinarian_id`),
  KEY `idx_created_at`      (`created_at`),
  CONSTRAINT `fk_dr_cow` FOREIGN KEY (`cow_id`)          REFERENCES `cows`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dr_vet` FOREIGN KEY (`veterinarian_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 23. BREEDING RECORDS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `breeding_records` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cow_id`                INT UNSIGNED NOT NULL,
  `heat_cycle_date`       DATE DEFAULT NULL,
  `insemination_date`     DATE DEFAULT NULL,
  `breeding_date`         DATE DEFAULT NULL,
  `expected_calving_date` DATE DEFAULT NULL,
  `actual_calving_date`   DATE DEFAULT NULL,
  `status`                ENUM('heat','inseminated','pregnant','calved','failed') NOT NULL DEFAULT 'heat',
  `recorded_by`           INT UNSIGNED NOT NULL,
  `notes`                 TEXT DEFAULT NULL,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cow_id`                (`cow_id`),
  KEY `idx_expected_calving_date` (`expected_calving_date`),
  KEY `idx_status`                (`status`),
  CONSTRAINT `fk_br_cow`  FOREIGN KEY (`cow_id`)      REFERENCES `cows`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_br_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 24. CALF RECORDS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `calf_records` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `breeding_record_id` INT UNSIGNED NOT NULL,
  `mother_cow_id`      INT UNSIGNED NOT NULL,
  `calf_tag_number`    VARCHAR(50)  NOT NULL,
  `birth_date`         DATE NOT NULL,
  `birth_weight`       DECIMAL(8,2) DEFAULT NULL,
  `gender`             ENUM('male','female') NOT NULL,
  `status`             ENUM('alive','deceased','sold') NOT NULL DEFAULT 'alive',
  `notes`              TEXT DEFAULT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_calf_tag` (`calf_tag_number`),
  KEY `idx_mother_cow_id` (`mother_cow_id`),
  KEY `idx_birth_date`    (`birth_date`),
  CONSTRAINT `fk_cr_breeding` FOREIGN KEY (`breeding_record_id`) REFERENCES `breeding_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cr_mother`   FOREIGN KEY (`mother_cow_id`)      REFERENCES `cows`             (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- SEED: Default module settings (all enabled)
-- ------------------------------------------------------------
INSERT INTO `module_settings` (`module_name`, `is_enabled`) VALUES
  ('cows',          1),
  ('milk',          1),
  ('sales',         1),
  ('workers',       1),
  ('feed_medicine', 1),
  ('equipment',     1),
  ('maintenance',   1),
  ('finance',       1),
  ('diagnosis',     1),
  ('breeding',      1),
  ('reports',       1),
  ('alerts',        1)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

SET FOREIGN_KEY_CHECKS = 1;

-- After importing this file, open setup.php in your browser
-- to create the first admin user, then DELETE setup.php.
