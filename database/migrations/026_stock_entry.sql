-- Migration 026: Stock Entry document (Supply Chain → New Stock Entry).
--
-- Replaces the old single-product "New Stock Movement" form with a proper
-- multi-line Stock Entry document laid out like the Purchase Order: six
-- tabs (Details, Items, Batch & Serial, Additional Costs, Transport, More
-- Info). A Stock Entry is saved as a draft and only touches stock when
-- it is submitted; cancelling a submitted entry reverses every movement.
--
-- Entry types:
--   material_receipt  – stock in to a target warehouse
--   material_issue    – stock out of a source warehouse
--   material_transfer – out of source, into target
--   stock_adjustment  – signed +/- correction at one warehouse
--
-- stock_entries           header (one row per entry)
-- stock_entry_items       lines, incl. batch / serial numbers per line
-- stock_entry_costs       additional costs (freight, handling, ...)
--                         spread over the lines on submit
-- product_serials gets a stock_entry_item_id so serials created by a
-- receipt can be traced (and removed again if the entry is cancelled).
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

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
  FOREIGN KEY (source_warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (target_warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
  FOREIGN KEY (shipping_partner_id) REFERENCES shipping_partners(id) ON DELETE SET NULL,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
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
  FOREIGN KEY (stock_entry_id) REFERENCES stock_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  FOREIGN KEY (source_warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (target_warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_entry_costs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stock_entry_id INT UNSIGNED NOT NULL,
  account_head_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(120) DEFAULT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (stock_entry_id) REFERENCES stock_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (account_head_id) REFERENCES ledger_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE product_serials ADD COLUMN IF NOT EXISTS stock_entry_item_id INT UNSIGNED DEFAULT NULL AFTER grn_item_id;

INSERT INTO ledger_accounts (name, account_type)
SELECT * FROM (SELECT x.name, x.account_type FROM (
  SELECT 'Handling Charges' name, 'other' account_type UNION ALL
  SELECT 'Loading / Unloading', 'other'
) x) t
WHERE NOT EXISTS (SELECT 1 FROM ledger_accounts la WHERE la.name = t.name);
