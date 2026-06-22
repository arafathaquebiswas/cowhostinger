-- ============================================================
-- Migration 011: Scope module_settings per farm
--
-- Before: one global row per module shared across all farms.
-- After:  one row per (farm_id, module_name), enabling per-farm
--         enable/disable toggles.
--
-- Run ONCE on existing databases.
-- Fresh installs use the updated schema.sql.
-- ============================================================

SET NAMES utf8mb4;

-- 1. Add farm_id (default 0 = legacy global placeholder)
ALTER TABLE `module_settings`
  ADD COLUMN IF NOT EXISTS `farm_id` INT UNSIGNED NOT NULL DEFAULT 0
  AFTER `id`;

-- 2. Duplicate global defaults for each existing farm
INSERT IGNORE INTO `module_settings` (`farm_id`, `module_name`, `is_enabled`)
SELECT f.id, m.module_name, m.is_enabled
FROM   `farms` f
       CROSS JOIN (SELECT `module_name`, `is_enabled`
                   FROM   `module_settings`
                   WHERE  `farm_id` = 0) AS m;

-- 3. Remove old global-scope unique index and replace with per-farm one
ALTER TABLE `module_settings`
  DROP INDEX IF EXISTS `uk_module_name`;

ALTER TABLE `module_settings`
  ADD UNIQUE KEY IF NOT EXISTS `uk_farm_module` (`farm_id`, `module_name`);

-- NOTE: farm_id = 0 seed rows remain as fallback templates so that
-- fresh installs without any registered farms still have module data.
-- isModuleEnabled() uses fid() which returns 0 when no farm is in
-- session — so those rows serve as safe defaults.
-- Once the first farm registers, register.php seeds proper per-farm rows.
