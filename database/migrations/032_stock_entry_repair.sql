-- Migration 032: Stock Entry repair.
--
-- Supply Chain > Stock Entries needs the tables from migration 026. On
-- some databases 026 was never run, or stopped part-way because MariaDB
-- refused one of its FOREIGN KEY clauses (for example when an older table's
-- id column has a different type). The Stock Entry pages then fail.
--
-- This creates the same tables as 026, with plain indexes instead of
-- foreign keys (the app already checks every id it saves), so it runs on
-- any database. Tables and columns that already exist are left untouched,
-- so it is safe to run whether or not 026 ran, and safe to re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stock_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no VARCHAR(30) NOT NULL UNIQUE,
  entry_type ENUM('material_receipt','material_issue','material_transfer','stock_adjustment') NOT NULL DEFAULT 'material_receipt',
  posting_date DATE NOT NULL,
  posting_time TIME DEFAULT NULL,
  purpose VARCHAR(120) DEFAULT NULL,
  source_warehouse_id INT UNSIGNED DEFAULT NULL,
  target_warehouse_id INT UNSIGNED DEFAULT NULL,
  vendor_id INT UNSIGNED DEFAULT NULL,
  reference_no VARCHAR(80) DEFAULT NULL,
  requested_by INT UNSIGNED DEFAULT NULL,
  department VARCHAR(120) DEFAULT NULL,
  project VARCHAR(120) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  -- Additional Costs tab
  distribute_costs_by ENUM('amount','qty') NOT NULL DEFAULT 'amount',
  total_qty INT NOT NULL DEFAULT 0,
  total_outgoing_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_incoming_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_additional_costs DECIMAL(14,2) NOT NULL DEFAULT 0,
  value_difference DECIMAL(14,2) NOT NULL DEFAULT 0,
  -- Transport tab
  mode_of_transport VARCHAR(30) DEFAULT NULL,
  shipping_partner_id INT UNSIGNED DEFAULT NULL,
  vehicle_no VARCHAR(30) DEFAULT NULL,
  driver_name VARCHAR(120) DEFAULT NULL,
  driver_phone VARCHAR(30) DEFAULT NULL,
  tracking_no VARCHAR(80) DEFAULT NULL,
  eway_bill_no VARCHAR(30) DEFAULT NULL,
  dispatch_date DATE DEFAULT NULL,
  expected_arrival_date DATE DEFAULT NULL,
  transport_remarks VARCHAR(255) DEFAULT NULL,
  -- More Info tab
  cost_center VARCHAR(120) DEFAULT NULL,
  business_unit VARCHAR(120) DEFAULT NULL,
  approver_id INT UNSIGNED DEFAULT NULL,
  inspection_required TINYINT(1) NOT NULL DEFAULT 0,
  remarks_internal TEXT DEFAULT NULL,
  tags VARCHAR(255) DEFAULT NULL,
  status ENUM('draft','submitted','cancelled') NOT NULL DEFAULT 'draft',
  submitted_at DATETIME DEFAULT NULL,
  submitted_by INT UNSIGNED DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_source_warehouse_id (source_warehouse_id),
  KEY idx_target_warehouse_id (target_warehouse_id),
  KEY idx_vendor_id (vendor_id),
  KEY idx_shipping_partner_id (shipping_partner_id),
  KEY idx_requested_by (requested_by),
  KEY idx_approver_id (approver_id),
  KEY idx_submitted_by (submitted_by),
  KEY idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_entry_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stock_entry_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  source_warehouse_id INT UNSIGNED DEFAULT NULL,
  target_warehouse_id INT UNSIGNED DEFAULT NULL,
  -- Signed only for stock_adjustment lines (negative = reduce stock).
  quantity INT NOT NULL,
  uom VARCHAR(30) NOT NULL DEFAULT 'pcs',
  uom_conversion_factor DECIMAL(14,4) NOT NULL DEFAULT 1,
  basic_rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  basic_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  additional_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  valuation_rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  batch_no VARCHAR(60) DEFAULT NULL,
  manufacturing_date DATE DEFAULT NULL,
  expiry_date DATE DEFAULT NULL,
  serial_numbers TEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  KEY idx_stock_entry_id (stock_entry_id),
  KEY idx_product_id (product_id),
  KEY idx_source_warehouse_id (source_warehouse_id),
  KEY idx_target_warehouse_id (target_warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_entry_costs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stock_entry_id INT UNSIGNED NOT NULL,
  account_head_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(120) DEFAULT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  KEY idx_stock_entry_id (stock_entry_id),
  KEY idx_account_head_id (account_head_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE product_serials ADD COLUMN IF NOT EXISTS stock_entry_item_id INT UNSIGNED DEFAULT NULL AFTER grn_item_id;

INSERT INTO ledger_accounts (name, account_type)
SELECT * FROM (SELECT x.name, x.account_type FROM (
  SELECT 'Handling Charges' name, 'other' account_type UNION ALL
  SELECT 'Loading / Unloading', 'other'
) x) t
WHERE NOT EXISTS (SELECT 1 FROM ledger_accounts la WHERE la.name = t.name);
