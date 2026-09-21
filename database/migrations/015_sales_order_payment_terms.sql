-- Migration 015: Sales Order redesign, Phase 4 (Payment Terms tab).
--
-- Adds a Payment Terms Templates master and the Sales Order's Payment
-- Terms tab: General Terms, a Payment Schedule (percentage rows resolved
-- against the order's own grand total, computed server-side the same
-- way tax rows are), Advance Payment, Late Payment, and Additional
-- Information sections, matching the mockup.
--
-- customers.credit_limit is new too — the mockup's Credit Limit field is
-- read-only on the order (auto-fetched from the customer), so it belongs
-- on the customer record, not duplicated per order.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

SET NAMES utf8mb4;

ALTER TABLE customers ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(14,2) DEFAULT NULL AFTER address;

CREATE TABLE IF NOT EXISTS payment_terms_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_terms_template_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  due_on ENUM('order_date','on_delivery','fixed_days') NOT NULL DEFAULT 'order_date',
  days_from INT NOT NULL DEFAULT 0,
  payment_type ENUM('advance','part_payment','balance') NOT NULL DEFAULT 'balance',
  percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(120) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (template_id) REFERENCES payment_terms_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Frozen schedule rows applied to one Sales Order (from a template or
-- entered manually), same pattern as sales_order_taxes.
CREATE TABLE IF NOT EXISTS sales_order_payment_schedule (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  due_on ENUM('order_date','on_delivery','fixed_days') NOT NULL DEFAULT 'order_date',
  days_from INT NOT NULL DEFAULT 0,
  payment_type ENUM('advance','part_payment','balance') NOT NULL DEFAULT 'balance',
  percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(120) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS payment_terms_template_id INT UNSIGNED DEFAULT NULL AFTER include_shipping_in_total;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS payment_terms VARCHAR(120) DEFAULT NULL AFTER payment_terms_template_id;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS payment_method VARCHAR(60) DEFAULT NULL AFTER payment_terms;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS payment_due_date_basis ENUM('against_delivery','against_order_date','fixed_date') NOT NULL DEFAULT 'against_delivery' AFTER payment_method;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS payment_instructions TEXT DEFAULT NULL AFTER payment_due_date_basis;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS require_advance_payment TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_instructions;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS advance_percentage DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER require_advance_payment;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS advance_valid_till DATE DEFAULT NULL AFTER advance_percentage;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS interest_on_late_payment TINYINT(1) NOT NULL DEFAULT 0 AFTER advance_valid_till;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS late_interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER interest_on_late_payment;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS late_grace_period_days INT NOT NULL DEFAULT 0 AFTER late_interest_rate;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS late_payment_terms VARCHAR(255) DEFAULT NULL AFTER late_grace_period_days;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(120) DEFAULT NULL AFTER late_payment_terms;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS special_payment_terms VARCHAR(255) DEFAULT NULL AFTER payment_reference;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS allow_partial_payments TINYINT(1) NOT NULL DEFAULT 1 AFTER special_payment_terms;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS send_payment_reminder TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_partial_payments;

INSERT INTO payment_terms_templates (id, name)
SELECT * FROM (SELECT 1 id, 'Standard 30 Days' name) t
WHERE NOT EXISTS (SELECT 1 FROM payment_terms_templates WHERE name = 'Standard 30 Days');

INSERT INTO payment_terms_template_items (template_id, due_on, days_from, payment_type, percentage, remarks, sort_order)
SELECT pt.id, 'order_date', 0, 'advance', 30, 'Advance payment', 1
FROM payment_terms_templates pt WHERE pt.name = 'Standard 30 Days'
  AND NOT EXISTS (SELECT 1 FROM payment_terms_template_items WHERE template_id = pt.id AND remarks = 'Advance payment');

INSERT INTO payment_terms_template_items (template_id, due_on, days_from, payment_type, percentage, remarks, sort_order)
SELECT pt.id, 'fixed_days', 30, 'balance', 70, 'Balance within 30 days', 2
FROM payment_terms_templates pt WHERE pt.name = 'Standard 30 Days'
  AND NOT EXISTS (SELECT 1 FROM payment_terms_template_items WHERE template_id = pt.id AND remarks = 'Balance within 30 days');
