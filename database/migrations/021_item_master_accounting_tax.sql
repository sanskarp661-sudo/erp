-- Migration 021: Item Master redesign, Phase 4 (Accounting + Tax & Charges tabs).
--
-- Account head mappings, valuation/cost-allocation fields, and tax
-- classification fields on products, plus product_taxes/product_charges
-- (item-level default tax/charge rows and additional charges,
-- mirroring tax_template_items' shape). Fields the mockup shows on both
-- the Accounting and Tax & Charges tabs (nil-rated/exempt/reverse-charge/
-- TDS/tax category) are stored once and edited only from the Tax &
-- Charges tab, to avoid two inputs sharing a name inside the same
-- <form> silently dropping whichever one the browser submits second.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

SET NAMES utf8mb4;

ALTER TABLE products ADD COLUMN IF NOT EXISTS income_account_id INT UNSIGNED DEFAULT NULL AFTER maximum_selling_price;
ALTER TABLE products ADD COLUMN IF NOT EXISTS cogs_account_id INT UNSIGNED DEFAULT NULL AFTER income_account_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS purchase_expense_account_id INT UNSIGNED DEFAULT NULL AFTER cogs_account_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS stock_in_hand_account_id INT UNSIGNED DEFAULT NULL AFTER purchase_expense_account_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS stock_adjustment_account_id INT UNSIGNED DEFAULT NULL AFTER stock_in_hand_account_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS under_over_valuation_account_id INT UNSIGNED DEFAULT NULL AFTER stock_adjustment_account_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS scrap_expense_account_id INT UNSIGNED DEFAULT NULL AFTER under_over_valuation_account_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS gain_loss_account_id INT UNSIGNED DEFAULT NULL AFTER scrap_expense_account_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS capitalization_threshold DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER gain_loss_account_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS include_in_period_closing_entry TINYINT(1) NOT NULL DEFAULT 1 AFTER capitalization_threshold;

ALTER TABLE products ADD COLUMN IF NOT EXISTS cost_center VARCHAR(120) DEFAULT NULL AFTER include_in_period_closing_entry;
ALTER TABLE products ADD COLUMN IF NOT EXISTS default_project VARCHAR(120) DEFAULT NULL AFTER cost_center;
ALTER TABLE products ADD COLUMN IF NOT EXISTS activity_type VARCHAR(120) DEFAULT NULL AFTER default_project;
ALTER TABLE products ADD COLUMN IF NOT EXISTS budget VARCHAR(120) DEFAULT NULL AFTER activity_type;

ALTER TABLE products ADD COLUMN IF NOT EXISTS tax_category VARCHAR(60) DEFAULT NULL AFTER budget;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_nil_rated TINYINT(1) NOT NULL DEFAULT 0 AFTER tax_category;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_exempt_from_tax TINYINT(1) NOT NULL DEFAULT 0 AFTER is_nil_rated;
ALTER TABLE products ADD COLUMN IF NOT EXISTS reverse_charge_applicable TINYINT(1) NOT NULL DEFAULT 0 AFTER is_exempt_from_tax;
ALTER TABLE products ADD COLUMN IF NOT EXISTS tds_applicable TINYINT(1) NOT NULL DEFAULT 0 AFTER reverse_charge_applicable;
ALTER TABLE products ADD COLUMN IF NOT EXISTS default_tax_template_id INT UNSIGNED DEFAULT NULL AFTER tds_applicable;
ALTER TABLE products ADD COLUMN IF NOT EXISTS price_includes_tax TINYINT(1) NOT NULL DEFAULT 0 AFTER default_tax_template_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS tax_calculation_based_on ENUM('Net Amount','Gross Amount') NOT NULL DEFAULT 'Net Amount' AFTER price_includes_tax;
ALTER TABLE products ADD COLUMN IF NOT EXISTS tax_exemption_reason VARCHAR(120) DEFAULT NULL AFTER tax_calculation_based_on;
ALTER TABLE products ADD COLUMN IF NOT EXISTS tax_exemption_applicable_from DATE DEFAULT NULL AFTER tax_exemption_reason;
ALTER TABLE products ADD COLUMN IF NOT EXISTS tax_notes VARCHAR(500) DEFAULT NULL AFTER tax_exemption_applicable_from;

CREATE TABLE IF NOT EXISTS product_taxes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  tax_charge_type VARCHAR(60) DEFAULT NULL,
  account_head_id INT UNSIGNED DEFAULT NULL,
  rate_or_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  included_in_price TINYINT(1) NOT NULL DEFAULT 0,
  applicable_on ENUM('net_total','grand_total') NOT NULL DEFAULT 'net_total',
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (account_head_id) REFERENCES ledger_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_charges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  charge_type VARCHAR(60) DEFAULT NULL,
  account_head_id INT UNSIGNED DEFAULT NULL,
  rate_or_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  applicable_on ENUM('net_total','grand_total') NOT NULL DEFAULT 'net_total',
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (account_head_id) REFERENCES ledger_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
