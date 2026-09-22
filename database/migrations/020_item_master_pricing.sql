-- Migration 020: Item Master redesign, Phase 3 (Pricing tab).
--
-- Adds Valuation & Pricing Method / Discount Rules / Other Pricing
-- Information fields to products, plus product_customer_prices for
-- per-customer rate/discount overrides. The Default Price List Rates
-- card reuses the existing price_lists/price_list_items tables from the
-- Sales Order redesign (rate only — no per-list discount/date-range,
-- unlike the new customer-specific table below, to avoid having two
-- different entry points, price_list_form.php and this tab, editing a
-- shared table's columns inconsistently). Price List Currency
-- Conversion from the mockup is deliberately not built: price_lists
-- already carries its own currency, and cross-currency conversion-rate
-- management is a treasury concern, not per-item master data.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

SET NAMES utf8mb4;

ALTER TABLE products ADD COLUMN IF NOT EXISTS price_determination ENUM('Based on Price List','Fixed Rate') NOT NULL DEFAULT 'Based on Price List' AFTER valuation_method;
ALTER TABLE products ADD COLUMN IF NOT EXISTS last_purchase_rate DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER price_determination;
ALTER TABLE products ADD COLUMN IF NOT EXISTS last_purchase_date DATE DEFAULT NULL AFTER last_purchase_rate;
ALTER TABLE products ADD COLUMN IF NOT EXISTS average_purchase_rate DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER last_purchase_date;

ALTER TABLE products ADD COLUMN IF NOT EXISTS allow_discount TINYINT(1) NOT NULL DEFAULT 1 AFTER average_purchase_rate;
ALTER TABLE products ADD COLUMN IF NOT EXISTS max_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER allow_discount;
ALTER TABLE products ADD COLUMN IF NOT EXISTS discount_account_id INT UNSIGNED DEFAULT NULL AFTER max_discount_percent;
ALTER TABLE products ADD COLUMN IF NOT EXISTS apply_discount_on ENUM('net_total','grand_total') NOT NULL DEFAULT 'net_total' AFTER discount_account_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS enable_additional_discount_sales TINYINT(1) NOT NULL DEFAULT 1 AFTER apply_discount_on;

ALTER TABLE products ADD COLUMN IF NOT EXISTS price_last_updated_at DATETIME DEFAULT NULL AFTER enable_additional_discount_sales;
ALTER TABLE products ADD COLUMN IF NOT EXISTS price_updated_by INT UNSIGNED DEFAULT NULL AFTER price_last_updated_at;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_price_editable_in_transactions TINYINT(1) NOT NULL DEFAULT 1 AFTER price_updated_by;
ALTER TABLE products ADD COLUMN IF NOT EXISTS include_in_price_suggestions TINYINT(1) NOT NULL DEFAULT 1 AFTER is_price_editable_in_transactions;
ALTER TABLE products ADD COLUMN IF NOT EXISTS allow_zero_price TINYINT(1) NOT NULL DEFAULT 0 AFTER include_in_price_suggestions;
ALTER TABLE products ADD COLUMN IF NOT EXISTS show_in_website TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_zero_price;
ALTER TABLE products ADD COLUMN IF NOT EXISTS minimum_selling_price DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER show_in_website;
ALTER TABLE products ADD COLUMN IF NOT EXISTS maximum_selling_price DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER minimum_selling_price;

CREATE TABLE IF NOT EXISTS product_customer_prices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  price_list_id INT UNSIGNED DEFAULT NULL,
  rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  valid_from DATE DEFAULT NULL,
  valid_to DATE DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
