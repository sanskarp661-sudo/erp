-- Migration 016: Sales Order redesign, Phase 5 (More Info tab).
--
-- Adds a Quotations module (list/form/view — a lightweight pre-order
-- proposal: header + items, mirroring the Sales Order's own original
-- shape before this redesign) so the Sales Order's "Reference Quotation"
-- field on the Details tab has something real to point at, a Sales Team
-- table (rep + role + commission %), and the More Info tab's Reference
-- Information / Additional Classification / Internal Information /
-- Custom Fields fields from the mockup.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS quotations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quotation_no VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  quotation_date DATE NOT NULL,
  valid_till DATE DEFAULT NULL,
  status ENUM('draft','sent','accepted','rejected','expired') NOT NULL DEFAULT 'draft',
  notes VARCHAR(255) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quotation_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quotation_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_order_sales_team (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  sales_person_id INT UNSIGNED NOT NULL,
  role VARCHAR(60) DEFAULT NULL,
  commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (sales_person_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS quotation_id INT UNSIGNED DEFAULT NULL AFTER send_payment_reminder;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS opportunity VARCHAR(120) DEFAULT NULL AFTER quotation_id;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS customer_po_date DATE DEFAULT NULL AFTER opportunity;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS campaign_source VARCHAR(120) DEFAULT NULL AFTER customer_po_date;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS sales_group VARCHAR(120) DEFAULT NULL AFTER campaign_source;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS sales_office VARCHAR(120) DEFAULT NULL AFTER sales_group;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS cost_center VARCHAR(120) DEFAULT NULL AFTER sales_office;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS business_unit VARCHAR(120) DEFAULT NULL AFTER cost_center;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS valid_till DATE DEFAULT NULL AFTER business_unit;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS order_type VARCHAR(60) NOT NULL DEFAULT 'Standard Order' AFTER valid_till;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS tags VARCHAR(255) DEFAULT NULL AFTER order_type;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS remarks_internal VARCHAR(500) DEFAULT NULL AFTER tags;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS end_customer VARCHAR(150) DEFAULT NULL AFTER remarks_internal;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS channel_partner VARCHAR(150) DEFAULT NULL AFTER end_customer;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS deal_registration_no VARCHAR(120) DEFAULT NULL AFTER channel_partner;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS market_segment VARCHAR(120) DEFAULT NULL AFTER deal_registration_no;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS region VARCHAR(120) DEFAULT NULL AFTER market_segment;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS expected_close_date DATE DEFAULT NULL AFTER region;
