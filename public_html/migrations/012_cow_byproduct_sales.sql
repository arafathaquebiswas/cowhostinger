-- ============================================================
-- Migration 012: Cow Byproduct Sales
-- Tracks all non-whole-cow and non-meat sale types:
-- skin, bones, fat, organs, dung, semen, breeding_service
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `cow_byproduct_sales` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `farm_id`        INT UNSIGNED  NOT NULL,
  `cow_id`         INT UNSIGNED  NOT NULL,
  `sale_type`      ENUM('skin','bones','fat','organs','dung','semen','breeding_service','other') NOT NULL,
  `description`    VARCHAR(255)  DEFAULT NULL,
  `quantity`       DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit`           VARCHAR(20)   NOT NULL DEFAULT 'unit',  -- kg, bag, litre, unit, dose
  `price_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount`   DECIMAL(12,2) NOT NULL,
  `buyer_name`     VARCHAR(150)  DEFAULT NULL,
  `sale_date`      DATE NOT NULL,
  `notes`          TEXT          DEFAULT NULL,
  `recorded_by`    INT UNSIGNED  NOT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_farm_cow`  (`farm_id`, `cow_id`),
  KEY `idx_sale_type` (`sale_type`),
  KEY `idx_sale_date` (`sale_date`),
  CONSTRAINT `fk_bps_farm` FOREIGN KEY (`farm_id`)     REFERENCES `farms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bps_cow`  FOREIGN KEY (`cow_id`)      REFERENCES `cows`  (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bps_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
