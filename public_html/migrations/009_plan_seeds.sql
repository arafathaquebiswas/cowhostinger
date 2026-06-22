-- ============================================================
-- Migration 009: Pricing Plan Update
-- Updates plan prices, limits, and feature flags to match
-- the Farm Management SaaS Platform Pricing Specification.
-- Adds can_payroll feature flag (Payroll = Pro+ only).
-- ============================================================

-- ── 1. Add can_payroll feature flag ──────────────────────────────────────────
ALTER TABLE `plans`
  ADD COLUMN IF NOT EXISTS `can_payroll` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Payroll Management — Pro plan and above'
    AFTER `can_milk_analytics`;

-- ── 2. Update plan data to match spec ────────────────────────────────────────

-- Free Plan: ৳0 | 20 cows | 3 workers | 5 equipment
UPDATE `plans` SET
  price_monthly      = 0,
  billing_days       = NULL,
  cows_limit         = 20,
  workers_limit      = 3,
  equipment_limit    = 5,
  users_limit        = 3,
  feed_limit         = NULL,
  medicine_limit     = NULL,
  diagnosis_limit    = NULL,
  can_export         = 1,
  can_finance        = 1,
  can_analytics      = 0,
  can_reports        = 0,
  can_milk_analytics = 0,
  can_payroll        = 0,
  is_active          = 1,
  is_featured        = 0
WHERE name = 'Free';

-- Basic Plan: ৳199/month | 50 cows | 10 workers | 20 equipment
UPDATE `plans` SET
  price_monthly      = 199,
  billing_days       = 30,
  cows_limit         = 50,
  workers_limit      = 10,
  equipment_limit    = 20,
  users_limit        = 10,
  feed_limit         = NULL,
  medicine_limit     = NULL,
  diagnosis_limit    = NULL,
  can_export         = 1,
  can_finance        = 1,
  can_analytics      = 1,
  can_reports        = 1,
  can_milk_analytics = 1,
  can_payroll        = 0,
  is_active          = 1,
  is_featured        = 0
WHERE name = 'Basic';

-- Pro Plan: ৳799/month | Unlimited cows | 50 workers | Unlimited equipment
UPDATE `plans` SET
  price_monthly      = 799,
  billing_days       = 30,
  cows_limit         = NULL,
  workers_limit      = 50,
  equipment_limit    = NULL,
  users_limit        = NULL,
  feed_limit         = NULL,
  medicine_limit     = NULL,
  diagnosis_limit    = NULL,
  can_export         = 1,
  can_finance        = 1,
  can_analytics      = 1,
  can_reports        = 1,
  can_milk_analytics = 1,
  can_payroll        = 1,
  is_active          = 1,
  is_featured        = 1
WHERE name = 'Pro';

-- Enterprise Plan: ৳1999/month | Unlimited everything
UPDATE `plans` SET
  price_monthly      = 1999,
  billing_days       = 30,
  cows_limit         = NULL,
  workers_limit      = NULL,
  equipment_limit    = NULL,
  users_limit        = NULL,
  feed_limit         = NULL,
  medicine_limit     = NULL,
  diagnosis_limit    = NULL,
  can_export         = 1,
  can_finance        = 1,
  can_analytics      = 1,
  can_reports        = 1,
  can_milk_analytics = 1,
  can_payroll        = 1,
  is_active          = 1,
  is_featured        = 0
WHERE name = 'Enterprise';

-- ── 3. Clear any stale featured flags (Pro is the only featured plan) ─────────
UPDATE `plans` SET is_featured = 0 WHERE name != 'Pro';
UPDATE `plans` SET is_featured = 1 WHERE name  = 'Pro';

-- ── 4. Verify ─────────────────────────────────────────────────────────────────
SELECT id, name, price_monthly, cows_limit, workers_limit, equipment_limit,
       can_payroll, is_featured, is_active
FROM plans
ORDER BY price_monthly;
