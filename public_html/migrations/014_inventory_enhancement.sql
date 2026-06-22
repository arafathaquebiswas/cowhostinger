-- Migration 014: Inventory Enhancement
-- Run via: mysql -u root cow_management < migrations/014_inventory_enhancement.sql
-- PHP inline migrations handle ALTER TABLE for existing tables safely.
-- This file only creates the new inventory_transactions table.

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  farm_id          INT UNSIGNED    NOT NULL,
  item_type        ENUM('feed','medicine','equipment') NOT NULL,
  item_id          INT UNSIGNED    NOT NULL,
  item_name        VARCHAR(150)    NOT NULL,
  transaction_type ENUM('purchase','sale','adjustment_add','adjustment_remove','use','waste') NOT NULL,
  quantity         DECIMAL(10,2)   NOT NULL,
  unit             VARCHAR(30)     NOT NULL DEFAULT 'unit',
  unit_cost        DECIMAL(10,2)   DEFAULT NULL,
  total_value      DECIMAL(12,2)   DEFAULT NULL,
  reference_type   VARCHAR(50)     DEFAULT NULL,
  reference_id     INT UNSIGNED    DEFAULT NULL,
  notes            TEXT            DEFAULT NULL,
  recorded_by      INT UNSIGNED    NOT NULL,
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_farm_type (farm_id, item_type),
  KEY idx_item      (item_type, item_id),
  KEY idx_date      (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
