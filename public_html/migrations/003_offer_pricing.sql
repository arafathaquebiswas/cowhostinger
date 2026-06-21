
-- Migration 003: Offer / Promotional Pricing on Plans
-- Run once against the live database.
-- Adds per-plan offer pricing that CEO can toggle on/off.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `plans`
  ADD COLUMN `offer_price`  DECIMAL(10,2)  DEFAULT NULL   COMMENT 'Promotional price; NULL = no offer set'         AFTER `price_monthly`,
  ADD COLUMN `offer_active` TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '1 = offer is live right now'                AFTER `offer_price`,
  ADD COLUMN `offer_label`  VARCHAR(100)   DEFAULT NULL   COMMENT 'Short label e.g. "Eid Special – 20% Off"'       AFTER `offer_active`,
  ADD COLUMN `offer_end`    DATE           DEFAULT NULL   COMMENT 'Offer auto-expires on this date (NULL = manual)' AFTER `offer_label`;

-- Verify
SELECT id, name, price_monthly, offer_price, offer_active, offer_label, offer_end FROM plans;
