-- Migration 004: Plan Featured Badge
-- Adds is_featured column so CEO can set which plan shows "Most Popular" badge.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `plans`
  ADD COLUMN `is_featured` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Shows Most Popular badge on customer plans page — only one should be 1'
    AFTER `is_active`;

-- Default: Pro is featured (matches previous hardcoded behaviour)
UPDATE plans SET is_featured = 1 WHERE name = 'Pro';

-- Verify
SELECT id, name, price_monthly, is_featured FROM plans ORDER BY price_monthly;
