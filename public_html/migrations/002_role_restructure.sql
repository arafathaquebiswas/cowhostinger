-- Migration 002: Role System Restructure
-- Run this once against your live database.
-- Removes `reception` role, adds `manager` role.
-- Cleans up users.role ENUM to match the two-layer architecture.
--
-- Run order matters: rename existing reception users FIRST, then alter ENUM.
-- ─────────────────────────────────────────────────────────────────────────────

-- Step 1: Reassign any existing reception users to worker
--         (reception had view-only access; worker is the closest farm-level equivalent)
UPDATE users SET role = 'worker' WHERE role = 'reception';

-- Step 2: Add superadmin/support_staff to ENUM (platform roles) and add manager,
--         remove reception. Order in ENUM reflects hierarchy.
ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'superadmin',
    'support_staff',
    'admin',
    'manager',
    'accountant',
    'veterinarian',
    'worker'
  ) NOT NULL;

-- Step 3: Verify — should show 0 rows after migration
SELECT id, name, email, role FROM users WHERE role = 'reception';

-- ─────────────────────────────────────────────────────────────────────────────
-- OPTIONAL: Promote specific reception users to manager if they need more access
-- UPDATE users SET role = 'manager' WHERE id IN (/* list of user IDs */);
-- ─────────────────────────────────────────────────────────────────────────────
