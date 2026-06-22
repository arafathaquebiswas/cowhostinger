-- ============================================================
-- Migration 013: Sales Expansion — Milk, Feed, Medicine,
--                Family Consumption & Cow Family Transfer
-- ============================================================
SET NAMES utf8mb4;

-- ── Milk Customers ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `milk_customers` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `farm_id`         INT UNSIGNED  NOT NULL,
  `name`            VARCHAR(150)  NOT NULL,
  `phone`           VARCHAR(30)   DEFAULT NULL,
  `address`         VARCHAR(255)  DEFAULT NULL,
  `price_per_liter` DECIMAL(10,2) DEFAULT NULL,
  `payment_terms`   ENUM('daily','weekly','monthly','on_delivery') NOT NULL DEFAULT 'daily',
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `notes`           TEXT          DEFAULT NULL,
  `created_by`      INT UNSIGNED  DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_farm_active` (`farm_id`, `is_active`),
  CONSTRAINT `fk_mc_farm` FOREIGN KEY (`farm_id`) REFERENCES `farms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Milk Sales ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `milk_sales` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `farm_id`         INT UNSIGNED  NOT NULL,
  `customer_id`     INT UNSIGNED  DEFAULT NULL,
  `customer_name`   VARCHAR(150)  NOT NULL,
  `liters_sold`     DECIMAL(10,2) NOT NULL,
  `price_per_liter` DECIMAL(10,2) NOT NULL,
  `total_amount`    DECIMAL(12,2) NOT NULL,
  `payment_status`  ENUM('paid','pending','partial') NOT NULL DEFAULT 'paid',
  `amount_paid`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sale_date`       DATE          NOT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `recorded_by`     INT UNSIGNED  NOT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_farm_date`     (`farm_id`, `sale_date`),
  KEY `idx_customer`      (`customer_id`),
  KEY `idx_payment_status`(`farm_id`, `payment_status`),
  CONSTRAINT `fk_msale_farm`     FOREIGN KEY (`farm_id`)     REFERENCES `farms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msale_customer` FOREIGN KEY (`customer_id`) REFERENCES `milk_customers`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_msale_user`     FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Family Consumption (milk, feed, medicine, other) ─────────────────────────
CREATE TABLE IF NOT EXISTS `family_consumption` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `farm_id`          INT UNSIGNED  NOT NULL,
  `item_type`        ENUM('milk','feed','medicine','other') NOT NULL,
  `item_name`        VARCHAR(150)  NOT NULL,
  `quantity`         DECIMAL(10,2) NOT NULL,
  `unit`             VARCHAR(30)   NOT NULL DEFAULT 'unit',
  `estimated_value`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `consumption_date` DATE          NOT NULL,
  `notes`            TEXT          DEFAULT NULL,
  `recorded_by`      INT UNSIGNED  NOT NULL,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_farm_type` (`farm_id`, `item_type`),
  KEY `idx_date`      (`consumption_date`),
  CONSTRAINT `fk_fc_farm` FOREIGN KEY (`farm_id`)     REFERENCES `farms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fc_user` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Cow Family Transfers (gift / charity / internal use) ─────────────────────
CREATE TABLE IF NOT EXISTS `cow_family_transfers` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `farm_id`         INT UNSIGNED  NOT NULL,
  `cow_id`          INT UNSIGNED  NOT NULL,
  `transfer_type`   ENUM('family_gift','family_use','internal_use','charity','sacrifice') NOT NULL,
  `recipient_name`  VARCHAR(150)  DEFAULT NULL,
  `estimated_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `transfer_date`   DATE          NOT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `recorded_by`     INT UNSIGNED  NOT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_farm_cow`  (`farm_id`, `cow_id`),
  KEY `idx_date`      (`transfer_date`),
  CONSTRAINT `fk_cft_farm` FOREIGN KEY (`farm_id`)     REFERENCES `farms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cft_cow`  FOREIGN KEY (`cow_id`)      REFERENCES `cows`(`id`)  ON DELETE RESTRICT,
  CONSTRAINT `fk_cft_user` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feed Sales (selling excess feed inventory) ────────────────────────────────
CREATE TABLE IF NOT EXISTS `feed_sales` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `farm_id`         INT UNSIGNED  NOT NULL,
  `feed_item_id`    INT UNSIGNED  DEFAULT NULL,
  `item_name`       VARCHAR(150)  NOT NULL,
  `quantity`        DECIMAL(10,2) NOT NULL,
  `unit`            VARCHAR(30)   NOT NULL DEFAULT 'kg',
  `price_per_unit`  DECIMAL(10,2) NOT NULL,
  `total_amount`    DECIMAL(12,2) NOT NULL,
  `buyer_name`      VARCHAR(150)  DEFAULT NULL,
  `sale_date`       DATE          NOT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `recorded_by`     INT UNSIGNED  NOT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_farm_date` (`farm_id`, `sale_date`),
  KEY `idx_feed_item` (`feed_item_id`),
  CONSTRAINT `fk_fs_farm` FOREIGN KEY (`farm_id`)      REFERENCES `farms`(`id`)           ON DELETE CASCADE,
  CONSTRAINT `fk_fs_feed` FOREIGN KEY (`feed_item_id`) REFERENCES `feed_inventory`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_fs_user` FOREIGN KEY (`recorded_by`)  REFERENCES `users`(`id`)           ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Medicine Sales (selling unused medicine) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `medicine_sales` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `farm_id`          INT UNSIGNED  NOT NULL,
  `medicine_item_id` INT UNSIGNED  DEFAULT NULL,
  `item_name`        VARCHAR(150)  NOT NULL,
  `quantity`         DECIMAL(10,2) NOT NULL,
  `unit`             VARCHAR(30)   NOT NULL DEFAULT 'unit',
  `price_per_unit`   DECIMAL(10,2) NOT NULL,
  `total_amount`     DECIMAL(12,2) NOT NULL,
  `buyer_name`       VARCHAR(150)  DEFAULT NULL,
  `sale_date`        DATE          NOT NULL,
  `notes`            TEXT          DEFAULT NULL,
  `recorded_by`      INT UNSIGNED  NOT NULL,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_farm_date` (`farm_id`, `sale_date`),
  KEY `idx_med_item`  (`medicine_item_id`),
  CONSTRAINT `fk_meds_farm` FOREIGN KEY (`farm_id`)          REFERENCES `farms`(`id`)                ON DELETE CASCADE,
  CONSTRAINT `fk_meds_item` FOREIGN KEY (`medicine_item_id`) REFERENCES `medicine_inventory`(`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_meds_user` FOREIGN KEY (`recorded_by`)      REFERENCES `users`(`id`)                ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
