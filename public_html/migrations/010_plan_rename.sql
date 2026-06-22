-- ============================================================
-- Migration 010: Plan Restructure
-- Renames "Basic" → "Starter", rebalances pricing ladder,
-- and tightens Pro limits (was unlimited, now 200/50/100).
--
-- New ladder:
--   Free      ৳0       | 20 cows  | 3 workers  | 5 equip
--   Starter   ৳149/mo  | 50 cows  | 10 workers | 25 equip
--   Pro ⭐     ৳499/mo  | 200 cows | 50 workers | 100 equip
--   Enterprise ৳999/mo | Unlimited
-- ============================================================

-- ── 1. Rename Basic → Starter ─────────────────────────────────────────────────
UPDATE `plans` SET name = 'Starter' WHERE name = 'Basic';

-- ── 2. Update Free ────────────────────────────────────────────────────────────
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

-- ── 3. Update Starter ─────────────────────────────────────────────────────────
UPDATE `plans` SET
  price_monthly      = 149,
  billing_days       = 30,
  cows_limit         = 50,
  workers_limit      = 10,
  equipment_limit    = 25,
  users_limit        = 10,
  feed_limit         = NULL,
  medicine_limit     = NULL,
  diagnosis_limit    = NULL,
  can_export         = 1,
  can_finance        = 1,
  can_analytics      = 0,
  can_reports        = 1,
  can_milk_analytics = 0,
  can_payroll        = 0,
  is_active          = 1,
  is_featured        = 0
WHERE name = 'Starter';

-- ── 4. Update Pro ─────────────────────────────────────────────────────────────
UPDATE `plans` SET
  price_monthly      = 499,
  billing_days       = 30,
  cows_limit         = 200,
  workers_limit      = 50,
  equipment_limit    = 100,
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

-- ── 5. Update Enterprise ─────────────────────────────────────────────────────
UPDATE `plans` SET
  price_monthly      = 999,
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

-- ── 6. Enforce only Pro is featured ──────────────────────────────────────────
UPDATE `plans` SET is_featured = 0 WHERE name != 'Pro';
UPDATE `plans` SET is_featured = 1 WHERE name  = 'Pro';

-- ── 7. Verify ────────────────────────────────────────────────────────────────
SELECT id, name, price_monthly, cows_limit, workers_limit, equipment_limit,
       can_reports, can_milk_analytics, can_payroll, is_featured
FROM plans ORDER BY price_monthly;
