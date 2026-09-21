-- Migration 013: Sales Order redesign, Phase 2 (Taxes & Charges tab).
--
-- Adds a real Chart of Accounts (ledger_accounts) and reusable Tax
-- Templates, and wires a Taxes & Charges table onto each Sales Order.
--
-- sales_orders.total_amount now means GRAND TOTAL (net amount + charges +
-- tax + adjustments, rounded) rather than the pre-tax items sum — this
-- is safe: nothing downstream (Delivery Note, dashboard, Sales Report)
-- assumed it excluded tax, they just summed it as "the order's value",
-- so the change only makes those figures more accurate. The pre-tax
-- items sum is now kept separately in the new net_amount column.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ledger_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  account_type ENUM('tax','income','expense','other') NOT NULL DEFAULT 'other',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ledger_accounts (name, account_type)
SELECT * FROM (SELECT x.name, x.account_type FROM (
  SELECT 'Output CGST' name, 'tax' account_type UNION ALL
  SELECT 'Output SGST', 'tax' UNION ALL
  SELECT 'Output IGST', 'tax' UNION ALL
  SELECT 'Freight Charges', 'other' UNION ALL
  SELECT 'Packaging Charges', 'other'
) x) t
WHERE NOT EXISTS (SELECT 1 FROM ledger_accounts la WHERE la.name = t.name);

CREATE TABLE IF NOT EXISTS tax_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tax_template_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tax_template_id INT UNSIGNED NOT NULL,
  type ENUM('on_item','on_order') NOT NULL DEFAULT 'on_item',
  account_head_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(120) DEFAULT NULL,
  based_on ENUM('net_amount','actual_amount') NOT NULL DEFAULT 'net_amount',
  rate_or_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (tax_template_id) REFERENCES tax_templates(id) ON DELETE CASCADE,
  FOREIGN KEY (account_head_id) REFERENCES ledger_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Applied tax/charge rows on one Sales Order — copied from a template (or
-- entered manually) and frozen at that point, same pattern as line items.
CREATE TABLE IF NOT EXISTS sales_order_taxes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  type ENUM('on_item','on_order') NOT NULL DEFAULT 'on_item',
  account_head_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(120) DEFAULT NULL,
  based_on ENUM('net_amount','actual_amount') NOT NULL DEFAULT 'net_amount',
  rate_or_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (account_head_id) REFERENCES ledger_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS net_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total_amount;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS tax_template_id INT UNSIGNED DEFAULT NULL AFTER net_amount;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS place_of_supply VARCHAR(120) DEFAULT NULL AFTER tax_template_id;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS gst_category ENUM('registered_business','unregistered_business','consumer','overseas','sez') DEFAULT NULL AFTER place_of_supply;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS reverse_charge TINYINT(1) NOT NULL DEFAULT 0 AFTER gst_category;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS tax_remarks VARCHAR(255) DEFAULT NULL AFTER reverse_charge;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS rounding_method ENUM('nearest','up','down') NOT NULL DEFAULT 'nearest' AFTER tax_remarks;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS rounding_precision DECIMAL(6,2) NOT NULL DEFAULT 0.01 AFTER rounding_method;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS additional_discount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER rounding_precision;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS additional_charge DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER additional_discount;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS adjustment_type ENUM('none','add','subtract') NOT NULL DEFAULT 'none' AFTER additional_charge;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS adjustment_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER adjustment_type;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS adjustment_remarks VARCHAR(255) DEFAULT NULL AFTER adjustment_amount;

-- Backfill: every existing order's net_amount = its old total_amount
-- (the pre-tax items sum, since no order had tax before this migration).
-- total_amount stays exactly as it was (no taxes existed to add).
UPDATE sales_orders SET net_amount = total_amount WHERE net_amount = 0;

INSERT INTO tax_templates (id, name)
SELECT * FROM (SELECT 1 id, 'GST - Standard (Sales)' name) t
WHERE NOT EXISTS (SELECT 1 FROM tax_templates WHERE name = 'GST - Standard (Sales)');

INSERT INTO tax_template_items (tax_template_id, type, account_head_id, description, based_on, rate_or_amount, sort_order)
SELECT tt.id, 'on_item', (SELECT id FROM ledger_accounts WHERE name = 'Output CGST'), 'CGST @ 9%', 'net_amount', 9, 1
FROM tax_templates tt WHERE tt.name = 'GST - Standard (Sales)'
  AND NOT EXISTS (SELECT 1 FROM tax_template_items WHERE tax_template_id = tt.id AND description = 'CGST @ 9%');

INSERT INTO tax_template_items (tax_template_id, type, account_head_id, description, based_on, rate_or_amount, sort_order)
SELECT tt.id, 'on_item', (SELECT id FROM ledger_accounts WHERE name = 'Output SGST'), 'SGST @ 9%', 'net_amount', 9, 2
FROM tax_templates tt WHERE tt.name = 'GST - Standard (Sales)'
  AND NOT EXISTS (SELECT 1 FROM tax_template_items WHERE tax_template_id = tt.id AND description = 'SGST @ 9%');
