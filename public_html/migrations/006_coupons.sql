-- Migration 006: Coupon codes
CREATE TABLE IF NOT EXISTS `coupons` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`           VARCHAR(32)  NOT NULL,
  `discount_type`  ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(10,2) NOT NULL,
  `plan_id`        INT UNSIGNED DEFAULT NULL COMMENT 'NULL = valid for all plans',
  `max_uses`       INT UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
  `used_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at`     DATE DEFAULT NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`     INT UNSIGNED NOT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_code` (`code`),
  INDEX `idx_active` (`is_active`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Track coupon used on each payment
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `coupon_id`       INT UNSIGNED DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `coupon_code`     VARCHAR(32)  DEFAULT NULL AFTER `coupon_id`,
  ADD COLUMN IF NOT EXISTS `coupon_discount` DECIMAL(10,2) DEFAULT NULL AFTER `coupon_code`;
