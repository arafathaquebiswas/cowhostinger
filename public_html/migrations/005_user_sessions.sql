-- Migration 005: Multi-Device Login Control
-- One user ID = max 2 active sessions at the same time.
-- Strategy: Auto-kick the oldest session when a 3rd device logs in.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id`          INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED     NOT NULL,
  `token`       VARCHAR(64)      NOT NULL,
  `device_fp`   VARCHAR(64)      NOT NULL COMMENT 'SHA-256 of device_cookie|user_agent',
  `ip_address`  VARCHAR(45)      DEFAULT NULL,
  `user_agent`  VARCHAR(500)     DEFAULT NULL,
  `login_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_active` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active`   TINYINT(1)       NOT NULL DEFAULT 1,
  UNIQUE  KEY `uq_token`       (`token`),
  INDEX         `idx_user_active` (`user_id`, `is_active`),
  INDEX         `idx_last_active` (`last_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
