-- ERP System Database Schema
-- Import this file via phpMyAdmin (Hostinger hPanel > Databases > phpMyAdmin)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Users & Auth
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  first_name VARCHAR(80) NOT NULL DEFAULT '',
  middle_name VARCHAR(80) DEFAULT NULL,
  last_name VARCHAR(80) DEFAULT NULL,
  username VARCHAR(60) DEFAULT NULL UNIQUE,
  language VARCHAR(20) NOT NULL DEFAULT 'en',
  time_zone VARCHAR(60) NOT NULL DEFAULT 'UTC',
  mobile_no VARCHAR(40) DEFAULT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  bio VARCHAR(500) DEFAULT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','manager','staff') NOT NULL DEFAULT 'staff',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A user can hold several roles at once; see includes/permissions.php's
-- ROLE_DEFS for the full role list and what each grants. The legacy
-- users.role column above is no longer read by the app.
CREATE TABLE IF NOT EXISTS user_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  role_key VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_role (user_id, role_key),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Generic activity log and comments, keyed by (entity_type, entity_id) so
-- they can be reused for any record — currently only wired up for users.
CREATE TABLE IF NOT EXISTS activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(30) NOT NULL,
  field_name VARCHAR(80) DEFAULT NULL,
  old_value VARCHAR(255) DEFAULT NULL,
  new_value VARCHAR(255) DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity_type, entity_id),
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED DEFAULT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity_type, entity_id),
  FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity_type, entity_id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Inventory
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Units of Measure master, maintained under Inventory -> Units of
-- Measure. products.unit stores the plain name string chosen from here
-- (no FK) so every page that already reads products.unit as text keeps
-- working unchanged — this table only backs the product form's dropdown.
CREATE TABLE IF NOT EXISTS uom (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(30) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO uom (name) VALUES ('pcs'), ('kg'), ('box'), ('litre'), ('meter'), ('dozen');

CREATE TABLE IF NOT EXISTS brands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS item_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Item Master redesign: products gained a large batch of ERPNext-style
-- fields across many phases (Details/Inventory/Pricing/Accounting/Sales/
-- Purchase/Tax/UOM/Batch-Serial/More Info tabs). default_warehouse_id and
-- default_price_list_id are deliberately plain columns with NO FK here —
-- warehouses/price_lists are declared later in this file, and reordering
-- this whole schema around them isn't worth the churn for two convenience
-- defaults; app code validates them instead.
CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  image VARCHAR(255) DEFAULT NULL,
  category_id INT UNSIGNED DEFAULT NULL,
  description TEXT DEFAULT NULL,
  item_category_id INT UNSIGNED DEFAULT NULL,
  brand_id INT UNSIGNED DEFAULT NULL,
  hsn_sac_code VARCHAR(20) DEFAULT NULL,
  is_stock_item TINYINT(1) NOT NULL DEFAULT 1,
  stock_item_type ENUM('Regular Stock Item','Non-Stock Item','Fixed Asset Item') NOT NULL DEFAULT 'Regular Stock Item',
  opening_stock_date DATE DEFAULT NULL,
  valuation_rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_sales_item TINYINT(1) NOT NULL DEFAULT 1,
  is_purchase_item TINYINT(1) NOT NULL DEFAULT 1,
  is_manufactured_item TINYINT(1) NOT NULL DEFAULT 0,
  is_sub_contracted_item TINYINT(1) NOT NULL DEFAULT 0,
  is_asset_item TINYINT(1) NOT NULL DEFAULT 0,
  has_variants TINYINT(1) NOT NULL DEFAULT 0,
  unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
  purchase_uom VARCHAR(30) DEFAULT NULL,
  sales_uom VARCHAR(30) DEFAULT NULL,
  purchase_uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  sales_uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  default_warehouse_id INT UNSIGNED DEFAULT NULL,
  default_bin VARCHAR(30) DEFAULT NULL,
  issue_method ENUM('FIFO','LIFO','Moving Average') NOT NULL DEFAULT 'FIFO',
  receipt_method ENUM('FIFO','LIFO','Moving Average') NOT NULL DEFAULT 'FIFO',
  allow_negative_stock TINYINT(1) NOT NULL DEFAULT 0,
  auto_create_batch_serial TINYINT(1) NOT NULL DEFAULT 0,
  default_price_list_id INT UNSIGNED DEFAULT NULL,
  min_stock_level INT NOT NULL DEFAULT 0,
  max_stock_level INT NOT NULL DEFAULT 0,
  lead_time_days INT NOT NULL DEFAULT 0,
  shelf_life_days INT NOT NULL DEFAULT 0,
  item_type ENUM('Finished Good','Raw Material','Service Item','Consumable') NOT NULL DEFAULT 'Finished Good',
  valuation_method ENUM('FIFO','LIFO','Moving Average') NOT NULL DEFAULT 'FIFO',
  price_determination ENUM('Based on Price List','Fixed Rate') NOT NULL DEFAULT 'Based on Price List',
  last_purchase_rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  last_purchase_date DATE DEFAULT NULL,
  average_purchase_rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  allow_discount TINYINT(1) NOT NULL DEFAULT 1,
  max_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_account_id INT UNSIGNED DEFAULT NULL,
  apply_discount_on ENUM('net_total','grand_total') NOT NULL DEFAULT 'net_total',
  enable_additional_discount_sales TINYINT(1) NOT NULL DEFAULT 1,
  price_last_updated_at DATETIME DEFAULT NULL,
  price_updated_by INT UNSIGNED DEFAULT NULL,
  is_price_editable_in_transactions TINYINT(1) NOT NULL DEFAULT 1,
  include_in_price_suggestions TINYINT(1) NOT NULL DEFAULT 1,
  allow_zero_price TINYINT(1) NOT NULL DEFAULT 0,
  show_in_website TINYINT(1) NOT NULL DEFAULT 0,
  minimum_selling_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  maximum_selling_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  income_account_id INT UNSIGNED DEFAULT NULL,
  cogs_account_id INT UNSIGNED DEFAULT NULL,
  purchase_expense_account_id INT UNSIGNED DEFAULT NULL,
  stock_in_hand_account_id INT UNSIGNED DEFAULT NULL,
  stock_adjustment_account_id INT UNSIGNED DEFAULT NULL,
  under_over_valuation_account_id INT UNSIGNED DEFAULT NULL,
  scrap_expense_account_id INT UNSIGNED DEFAULT NULL,
  gain_loss_account_id INT UNSIGNED DEFAULT NULL,
  capitalization_threshold DECIMAL(14,2) NOT NULL DEFAULT 0,
  include_in_period_closing_entry TINYINT(1) NOT NULL DEFAULT 1,
  cost_center VARCHAR(120) DEFAULT NULL,
  default_project VARCHAR(120) DEFAULT NULL,
  activity_type VARCHAR(120) DEFAULT NULL,
  budget VARCHAR(120) DEFAULT NULL,
  tax_category VARCHAR(60) DEFAULT NULL,
  is_nil_rated TINYINT(1) NOT NULL DEFAULT 0,
  is_exempt_from_tax TINYINT(1) NOT NULL DEFAULT 0,
  reverse_charge_applicable TINYINT(1) NOT NULL DEFAULT 0,
  tds_applicable TINYINT(1) NOT NULL DEFAULT 0,
  default_tax_template_id INT UNSIGNED DEFAULT NULL,
  price_includes_tax TINYINT(1) NOT NULL DEFAULT 0,
  tax_calculation_based_on ENUM('Net Amount','Gross Amount') NOT NULL DEFAULT 'Net Amount',
  tax_exemption_reason VARCHAR(120) DEFAULT NULL,
  tax_exemption_applicable_from DATE DEFAULT NULL,
  tax_notes VARCHAR(500) DEFAULT NULL,
  default_supplier_id INT UNSIGNED DEFAULT NULL,
  default_purchase_price_list_id INT UNSIGNED DEFAULT NULL,
  purchase_min_order_qty INT NOT NULL DEFAULT 0,
  purchase_max_order_qty INT NOT NULL DEFAULT 0,
  purchase_order_qty_increment INT NOT NULL DEFAULT 1,
  receipt_tolerance_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  over_delivery_allowance_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  default_package_type VARCHAR(60) DEFAULT NULL,
  items_per_package INT NOT NULL DEFAULT 1,
  purchase_description VARCHAR(500) DEFAULT NULL,
  requires_purchase_order TINYINT(1) NOT NULL DEFAULT 1,
  allow_receipt_without_po TINYINT(1) NOT NULL DEFAULT 0,
  track_supplier_batch_serial TINYINT(1) NOT NULL DEFAULT 0,
  include_in_supplier_portal TINYINT(1) NOT NULL DEFAULT 0,
  is_drop_ship_item TINYINT(1) NOT NULL DEFAULT 0,
  allow_subcontracting TINYINT(1) NOT NULL DEFAULT 0,
  maintain_last_purchase_rate TINYINT(1) NOT NULL DEFAULT 0,
  inspection_required ENUM('Yes','No') NOT NULL DEFAULT 'No',
  sampling_rate_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  quality_rating_default VARCHAR(30) DEFAULT NULL,
  reject_if_quality_check_fails TINYINT(1) NOT NULL DEFAULT 0,
  item_customer_group VARCHAR(120) DEFAULT NULL,
  sales_min_order_qty INT NOT NULL DEFAULT 0,
  sales_max_order_qty INT NOT NULL DEFAULT 0,
  sales_order_qty_increment INT NOT NULL DEFAULT 1,
  sales_lead_time_days INT NOT NULL DEFAULT 0,
  delivery_time_days INT NOT NULL DEFAULT 0,
  weight_for_shipping_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
  sales_description VARCHAR(500) DEFAULT NULL,
  marketing_material VARCHAR(60) DEFAULT NULL,
  item_website VARCHAR(255) DEFAULT NULL,
  available_for_online_sales TINYINT(1) NOT NULL DEFAULT 1,
  available_for_retail_sales TINYINT(1) NOT NULL DEFAULT 1,
  available_for_b2b_sales TINYINT(1) NOT NULL DEFAULT 1,
  not_discountable TINYINT(1) NOT NULL DEFAULT 0,
  requires_approval_for_discount TINYINT(1) NOT NULL DEFAULT 0,
  show_in_customer_portal TINYINT(1) NOT NULL DEFAULT 0,
  default_monthly_sales_qty INT NOT NULL DEFAULT 0,
  seasonal_demand ENUM('Low','Normal','High') NOT NULL DEFAULT 'Normal',
  preferred_sales_warehouse_id INT UNSIGNED DEFAULT NULL,
  standard_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
  standard_volume_ltr DECIMAL(10,3) NOT NULL DEFAULT 0,
  gross_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
  net_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
  tags VARCHAR(255) DEFAULT NULL,
  cost_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  selling_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  quantity INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,
  reorder_qty INT NOT NULL DEFAULT 0,
  safety_stock INT NOT NULL DEFAULT 0,
  enable_reorder_notifications TINYINT(1) NOT NULL DEFAULT 1,
  consider_in_mrp TINYINT(1) NOT NULL DEFAULT 1,
  has_batch_no TINYINT(1) NOT NULL DEFAULT 0,
  has_serial_no TINYINT(1) NOT NULL DEFAULT 0,
  batch_expiry_required TINYINT(1) NOT NULL DEFAULT 0,
  batch_number_series VARCHAR(40) DEFAULT NULL,
  storage_section VARCHAR(60) DEFAULT NULL,
  storage_rack VARCHAR(30) DEFAULT NULL,
  storage_shelf VARCHAR(30) DEFAULT NULL,
  storage_bin VARCHAR(30) DEFAULT NULL,
  track_stock_ageing TINYINT(1) NOT NULL DEFAULT 0,
  include_in_stock_report TINYINT(1) NOT NULL DEFAULT 1,
  allow_stock_transfer TINYINT(1) NOT NULL DEFAULT 1,
  is_kit_or_set TINYINT(1) NOT NULL DEFAULT 0,
  use_alternative_item TINYINT(1) NOT NULL DEFAULT 0,
  restrict_warehouse TINYINT(1) NOT NULL DEFAULT 0,
  block_for_stock_transactions TINYINT(1) NOT NULL DEFAULT 0,
  exclude_from_inventory_valuation TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  FOREIGN KEY (item_category_id) REFERENCES item_categories(id) ON DELETE SET NULL,
  FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
  FOREIGN KEY (price_updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_barcodes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  barcode VARCHAR(60) NOT NULL,
  barcode_type VARCHAR(30) DEFAULT NULL,
  uom VARCHAR(30) DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The real per-item alternate-UOM list: every UOM a line item can be
-- transacted in for this product, and how many stock-UOM units one of
-- it equals. Distinct from products.purchase_uom/sales_uom, which just
-- remember which single UOM here a product's Purchase/Sales tab
-- defaults to.
CREATE TABLE IF NOT EXISTS product_uoms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  uom VARCHAR(30) NOT NULL,
  conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_uom (product_id, uom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A batch/lot for a batch-tracked item. Created the first time its batch_no
-- is received on a GRN (find-or-create by product_id+batch_no); stays on
-- record afterward even if its stock later reaches zero, for traceability.
CREATE TABLE IF NOT EXISTS product_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  batch_no VARCHAR(60) NOT NULL,
  manufacturing_date DATE DEFAULT NULL,
  expiry_date DATE DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_batch (product_id, batch_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Warehouses form a tree (self-referencing parent_id). A "Group" warehouse
-- is organizational only (e.g. "All Warehouses") and can't hold stock
-- itself — only its non-group descendants can.
CREATE TABLE IF NOT EXISTS warehouses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  parent_id INT UNSIGNED DEFAULT NULL,
  is_group TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parent_id) REFERENCES warehouses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per physical serialized unit ever received. grn_item_id has no
-- inline FK — goods_receipt_items is declared much later in this file
-- (Purchases section); app code validates it instead, same convention
-- used elsewhere in this schema for forward references.
CREATE TABLE IF NOT EXISTS product_serials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  serial_no VARCHAR(80) NOT NULL,
  batch_id INT UNSIGNED DEFAULT NULL,
  warehouse_id INT UNSIGNED DEFAULT NULL,
  status ENUM('in_stock','issued','damaged') NOT NULL DEFAULT 'in_stock',
  grn_item_id INT UNSIGNED DEFAULT NULL,
  stock_entry_item_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  UNIQUE KEY uq_product_serial (product_id, serial_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-(product, warehouse, batch) stock balance — batch_id is NULL for
-- non-batch-tracked stock, and MySQL/MariaDB treat NULL as distinct in a
-- unique key, so any number of NULL-batch rows can coexist per
-- product+warehouse (there's only ever one in practice, kept unique by
-- stock_move()'s own SELECT...FOR UPDATE lookup, not this key).
-- products.quantity is kept as a maintained total across all warehouses
-- and batches — see includes/stock.php.
CREATE TABLE IF NOT EXISTS stock_bins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED DEFAULT NULL,
  quantity INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_product_warehouse_batch (product_id, warehouse_id, batch_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
  FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED DEFAULT NULL,
  batch_id INT UNSIGNED DEFAULT NULL,
  type ENUM('in','out','adjustment') NOT NULL,
  quantity INT NOT NULL,
  reference VARCHAR(120) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Sales / CRM
-- ---------------------------------------------------------------------
-- Customer master (CRM). The *_id / default columns are what a new Sales
-- Order pre-fills from when the customer is picked; credit_hold and
-- bypass_credit_check drive the Sales Order's credit check. address is
-- the single free-text address prints read, kept in sync by the Customer
-- form with the default row in customer_addresses.
CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_code VARCHAR(30) DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  customer_type ENUM('company','individual') NOT NULL DEFAULT 'company',
  customer_group VARCHAR(60) DEFAULT NULL,
  company VARCHAR(150) DEFAULT NULL,
  territory VARCHAR(100) DEFAULT NULL,
  industry VARCHAR(100) DEFAULT NULL,
  website VARCHAR(150) DEFAULT NULL,
  status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
  contact_person VARCHAR(120) DEFAULT NULL,
  designation VARCHAR(100) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  mobile VARCHAR(40) DEFAULT NULL,
  alt_email VARCHAR(150) DEFAULT NULL,
  preferred_contact ENUM('email','phone','whatsapp','any') NOT NULL DEFAULT 'any',
  address VARCHAR(255) DEFAULT NULL,
  credit_limit DECIMAL(14,2) DEFAULT NULL,
  gstin VARCHAR(15) DEFAULT NULL,
  pan VARCHAR(10) DEFAULT NULL,
  gst_category VARCHAR(30) DEFAULT NULL,
  place_of_supply VARCHAR(60) DEFAULT NULL,
  tax_template_id INT UNSIGNED DEFAULT NULL,
  tax_exempt TINYINT(1) NOT NULL DEFAULT 0,
  exemption_certificate_no VARCHAR(60) DEFAULT NULL,
  price_list_id INT UNSIGNED DEFAULT NULL,
  currency VARCHAR(3) DEFAULT NULL,
  sales_person_id INT UNSIGNED DEFAULT NULL,
  sales_channel VARCHAR(40) DEFAULT NULL,
  market_segment VARCHAR(100) DEFAULT NULL,
  region VARCHAR(100) DEFAULT NULL,
  shipping_partner_id INT UNSIGNED DEFAULT NULL,
  delivery_terms VARCHAR(255) DEFAULT NULL,
  payment_terms_template_id INT UNSIGNED DEFAULT NULL,
  payment_method VARCHAR(40) DEFAULT NULL,
  credit_hold TINYINT(1) NOT NULL DEFAULT 0,
  bypass_credit_check TINYINT(1) NOT NULL DEFAULT 0,
  lead_source VARCHAR(60) DEFAULT NULL,
  referred_by VARCHAR(150) DEFAULT NULL,
  campaign VARCHAR(150) DEFAULT NULL,
  customer_since DATE DEFAULT NULL,
  tags VARCHAR(255) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_customer_code (customer_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A product's rate on a given price list. If a product has no explicit
-- row here, forms fall back to products.selling_price, so the seeded
-- "Standard Selling" list works without pricing every product twice.
CREATE TABLE IF NOT EXISTS price_lists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  currency VARCHAR(3) NOT NULL DEFAULT 'INR',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS price_list_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  price_list_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_pricelist_product (price_list_id, product_id),
  FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Item Master Pricing tab: per-customer rate/discount overrides. Unlike
-- price_list_items (shared, rate-only), this is item-scoped and carries
-- its own discount/date-range since only this tab edits it.
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

-- Additive multi-address book for a customer. customers.address stays as
-- the single legacy free-text field every existing page already reads;
-- this only backs the Sales Order's Customer Address dropdown.
CREATE TABLE IF NOT EXISTS customer_addresses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  label VARCHAR(60) NOT NULL DEFAULT 'Address',
  address_line VARCHAR(255) NOT NULL,
  city VARCHAR(100) DEFAULT NULL,
  state VARCHAR(100) DEFAULT NULL,
  pincode VARCHAR(20) DEFAULT NULL,
  country VARCHAR(100) NOT NULL DEFAULT 'India',
  contact_person VARCHAR(120) DEFAULT NULL,
  contact_phone VARCHAR(40) DEFAULT NULL,
  contact_email VARCHAR(150) DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Chart of Accounts (kept intentionally minimal — just enough to tag a
-- Tax Template row as a real ledger account). account_type='tax' is what
-- separates "Total Tax Amount" from "Total Charges" in the Sales Order's
-- Tax Summary panel.
CREATE TABLE IF NOT EXISTS ledger_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_code VARCHAR(20) DEFAULT NULL,
  name VARCHAR(120) NOT NULL UNIQUE,
  parent_id INT UNSIGNED DEFAULT NULL,
  is_group TINYINT(1) NOT NULL DEFAULT 0,
  root_type ENUM('asset','liability','equity','income','expense') DEFAULT NULL,
  account_type ENUM('tax','income','expense','other','bank','cash','receivable','payable','current_asset','fixed_asset','stock','current_liability','loan','equity','cost_of_goods_sold') NOT NULL DEFAULT 'other',
  account_nature ENUM('debit','credit') NOT NULL DEFAULT 'debit',
  statement_category VARCHAR(40) DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'INR',
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  opening_date DATE DEFAULT NULL,
  cost_center_id INT UNSIGNED DEFAULT NULL,
  project VARCHAR(100) DEFAULT NULL,
  is_bank TINYINT(1) NOT NULL DEFAULT 0,
  is_cash TINYINT(1) NOT NULL DEFAULT 0,
  bank_account_no VARCHAR(40) DEFAULT NULL,
  bank_ifsc VARCHAR(11) DEFAULT NULL,
  tax_applicability VARCHAR(20) DEFAULT NULL,
  default_tax_template_id INT UNSIGNED DEFAULT NULL,
  tags VARCHAR(255) DEFAULT NULL,
  system_key VARCHAR(40) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ledger_code (account_code),
  UNIQUE KEY uniq_ledger_system_key (system_key),
  INDEX idx_ledger_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Item Master Tax & Charges tab: default tax/charge rows for this item
-- (distinct tables, mirroring the mockup's separate "Default Taxes and
-- Charges" vs "Additional Charges" sections).
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

-- A reusable set of tax/charge rows, applied to a Sales Order in one
-- click via "Apply Template" (sales_order_taxes then holds a frozen copy).
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

-- A courier/logistics partner, referenced on the Sales Order's Shipping
-- & Delivery tab.
CREATE TABLE IF NOT EXISTS shipping_partners (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A reusable payment schedule, applied to a Sales Order in one click via
-- "Apply Template" (sales_order_payment_schedule then holds a frozen
-- copy, same pattern as tax_templates / sales_order_taxes).
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

-- A lightweight pre-order proposal (header + items only) — what a Sales
-- Order's "Reference Quotation" field points at once accepted.
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
  uom VARCHAR(30) NOT NULL DEFAULT 'pcs',
  uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_price DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- warehouse_id is optional and only used for the Stock Balance Report's
-- "Reserved Qty" column — a Sales Order never moves stock itself, that's
-- still exclusively the job of its Delivery Note. It's kept in sync with
-- the first line item's warehouse; the real per-item warehouse lives on
-- sales_order_items (items can ship from different warehouses).
--
-- total_amount is the GRAND TOTAL (net_amount + charges + tax +
-- adjustments, rounded); net_amount is the pre-tax items sum.
CREATE TABLE IF NOT EXISTS sales_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  contact_person VARCHAR(120) DEFAULT NULL,
  customer_address_id INT UNSIGNED DEFAULT NULL,
  warehouse_id INT UNSIGNED DEFAULT NULL,
  order_date DATE NOT NULL,
  required_delivery_date DATE DEFAULT NULL,
  promised_delivery_date DATE DEFAULT NULL,
  delivery_priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  fulfillment_type ENUM('complete_order','partial_allowed') NOT NULL DEFAULT 'complete_order',
  delivery_terms VARCHAR(60) DEFAULT NULL,
  shipping_rule VARCHAR(120) DEFAULT NULL,
  delivery_remarks VARCHAR(255) DEFAULT NULL,
  ship_to_address_id INT UNSIGNED DEFAULT NULL,
  shipping_partner_id INT UNSIGNED DEFAULT NULL,
  shipping_service_type VARCHAR(60) DEFAULT NULL,
  shipping_method VARCHAR(60) DEFAULT NULL,
  tracking_no VARCHAR(120) DEFAULT NULL,
  expected_dispatch_date DATE DEFAULT NULL,
  expected_delivery_date DATE DEFAULT NULL,
  create_dn_after_submit TINYINT(1) NOT NULL DEFAULT 1,
  update_stock_on_submit TINYINT(1) NOT NULL DEFAULT 1,
  allow_partial_delivery TINYINT(1) NOT NULL DEFAULT 0,
  notify_customer TINYINT(1) NOT NULL DEFAULT 0,
  print_picking_list TINYINT(1) NOT NULL DEFAULT 0,
  print_shipping_label TINYINT(1) NOT NULL DEFAULT 0,
  include_shipping_in_total TINYINT(1) NOT NULL DEFAULT 1,
  price_list_id INT UNSIGNED DEFAULT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'INR',
  sales_channel VARCHAR(60) DEFAULT NULL,
  territory VARCHAR(120) DEFAULT NULL,
  sales_person_id INT UNSIGNED DEFAULT NULL,
  customer_po_no VARCHAR(60) DEFAULT NULL,
  project VARCHAR(120) DEFAULT NULL,
  status ENUM('pending','confirmed','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
  channel ENUM('online','pos') NOT NULL DEFAULT 'online',
  notes VARCHAR(255) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_template_id INT UNSIGNED DEFAULT NULL,
  place_of_supply VARCHAR(120) DEFAULT NULL,
  gst_category ENUM('registered_business','unregistered_business','consumer','overseas','sez') DEFAULT NULL,
  reverse_charge TINYINT(1) NOT NULL DEFAULT 0,
  tax_remarks VARCHAR(255) DEFAULT NULL,
  rounding_method ENUM('nearest','up','down') NOT NULL DEFAULT 'nearest',
  rounding_precision DECIMAL(6,2) NOT NULL DEFAULT 0.01,
  additional_discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  additional_charge DECIMAL(14,2) NOT NULL DEFAULT 0,
  adjustment_type ENUM('none','add','subtract') NOT NULL DEFAULT 'none',
  adjustment_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  adjustment_remarks VARCHAR(255) DEFAULT NULL,
  payment_terms_template_id INT UNSIGNED DEFAULT NULL,
  payment_terms VARCHAR(120) DEFAULT NULL,
  payment_method VARCHAR(60) DEFAULT NULL,
  payment_due_date_basis ENUM('against_delivery','against_order_date','fixed_date') NOT NULL DEFAULT 'against_delivery',
  payment_instructions TEXT DEFAULT NULL,
  require_advance_payment TINYINT(1) NOT NULL DEFAULT 0,
  advance_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  advance_valid_till DATE DEFAULT NULL,
  interest_on_late_payment TINYINT(1) NOT NULL DEFAULT 0,
  late_interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  late_grace_period_days INT NOT NULL DEFAULT 0,
  late_payment_terms VARCHAR(255) DEFAULT NULL,
  payment_reference VARCHAR(120) DEFAULT NULL,
  special_payment_terms VARCHAR(255) DEFAULT NULL,
  allow_partial_payments TINYINT(1) NOT NULL DEFAULT 1,
  send_payment_reminder TINYINT(1) NOT NULL DEFAULT 0,
  quotation_id INT UNSIGNED DEFAULT NULL,
  opportunity VARCHAR(120) DEFAULT NULL,
  customer_po_date DATE DEFAULT NULL,
  campaign_source VARCHAR(120) DEFAULT NULL,
  sales_group VARCHAR(120) DEFAULT NULL,
  sales_office VARCHAR(120) DEFAULT NULL,
  cost_center VARCHAR(120) DEFAULT NULL,
  business_unit VARCHAR(120) DEFAULT NULL,
  valid_till DATE DEFAULT NULL,
  order_type VARCHAR(60) NOT NULL DEFAULT 'Standard Order',
  tags VARCHAR(255) DEFAULT NULL,
  remarks_internal VARCHAR(500) DEFAULT NULL,
  end_customer VARCHAR(150) DEFAULT NULL,
  channel_partner VARCHAR(150) DEFAULT NULL,
  deal_registration_no VARCHAR(120) DEFAULT NULL,
  market_segment VARCHAR(120) DEFAULT NULL,
  region VARCHAR(120) DEFAULT NULL,
  expected_close_date DATE DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_address_id) REFERENCES customer_addresses(id) ON DELETE SET NULL,
  FOREIGN KEY (ship_to_address_id) REFERENCES customer_addresses(id) ON DELETE SET NULL,
  FOREIGN KEY (shipping_partner_id) REFERENCES shipping_partners(id) ON DELETE SET NULL,
  FOREIGN KEY (payment_terms_template_id) REFERENCES payment_terms_templates(id) ON DELETE SET NULL,
  FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE SET NULL,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE SET NULL,
  FOREIGN KEY (sales_person_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (tax_template_id) REFERENCES tax_templates(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS sales_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  warehouse_id INT UNSIGNED DEFAULT NULL,
  quantity INT NOT NULL,
  uom VARCHAR(30) NOT NULL DEFAULT 'pcs',
  uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_price DECIMAL(14,2) NOT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- A Delivery Note is what actually deducts stock for a sale (Sales Orders
-- themselves no longer touch stock). draft = not yet processed, delivered
-- = stock has been deducted, cancelled = reversed (only from delivered).
CREATE TABLE IF NOT EXISTS delivery_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dn_no VARCHAR(30) NOT NULL UNIQUE,
  sales_order_id INT UNSIGNED DEFAULT NULL,
  customer_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  posting_date DATE NOT NULL,
  status ENUM('draft','delivered','cancelled') NOT NULL DEFAULT 'draft',
  notes VARCHAR(255) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_note_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dn_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL,
  uom VARCHAR(30) NOT NULL DEFAULT 'pcs',
  uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_price DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (dn_id) REFERENCES delivery_notes(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Purchases
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vendors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  company VARCHAR(150) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Item Master Purchase tab: preferred supplier list for this item.
CREATE TABLE IF NOT EXISTS product_suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  supplier_part_no VARCHAR(60) DEFAULT NULL,
  lead_time_days INT NOT NULL DEFAULT 0,
  last_purchase_rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_preferred TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (supplier_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Item Master Sales tab: per-customer quantity/discount rules — distinct
-- from product_customer_prices (Pricing tab's rate/date-range overrides).
CREATE TABLE IF NOT EXISTS product_customer_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  customer_group VARCHAR(120) DEFAULT NULL,
  price_list_id INT UNSIGNED DEFAULT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  min_qty INT NOT NULL DEFAULT 0,
  max_qty INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_no VARCHAR(30) NOT NULL UNIQUE,
  vendor_id INT UNSIGNED NOT NULL,
  vendor_contact VARCHAR(120) DEFAULT NULL,
  vendor_address VARCHAR(255) DEFAULT NULL,
  order_date DATE NOT NULL,
  required_by DATE DEFAULT NULL,
  purchase_type VARCHAR(60) DEFAULT NULL,
  buyer_id INT UNSIGNED DEFAULT NULL,
  price_list_id INT UNSIGNED DEFAULT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'INR',
  vendor_gstin VARCHAR(20) DEFAULT NULL,
  vendor_quote_no VARCHAR(60) DEFAULT NULL,
  material_request_no VARCHAR(60) DEFAULT NULL,
  project VARCHAR(120) DEFAULT NULL,
  status ENUM('pending','ordered','received','cancelled') NOT NULL DEFAULT 'pending',
  notes VARCHAR(255) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_template_id INT UNSIGNED DEFAULT NULL,
  place_of_supply VARCHAR(120) DEFAULT NULL,
  gst_category ENUM('registered_business','unregistered_business','composition','overseas','sez') DEFAULT NULL,
  reverse_charge TINYINT(1) NOT NULL DEFAULT 0,
  tax_remarks VARCHAR(255) DEFAULT NULL,
  rounding_method ENUM('nearest','up','down') NOT NULL DEFAULT 'nearest',
  rounding_precision DECIMAL(10,2) NOT NULL DEFAULT 0.01,
  additional_discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  additional_charge DECIMAL(14,2) NOT NULL DEFAULT 0,
  adjustment_type ENUM('none','add','subtract') NOT NULL DEFAULT 'none',
  adjustment_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  adjustment_remarks VARCHAR(255) DEFAULT NULL,
  ship_to_warehouse_id INT UNSIGNED DEFAULT NULL,
  expected_delivery_date DATE DEFAULT NULL,
  expected_dispatch_date DATE DEFAULT NULL,
  delivery_priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  delivery_terms VARCHAR(60) DEFAULT NULL,
  mode_of_transport VARCHAR(30) DEFAULT NULL,
  shipping_partner_id INT UNSIGNED DEFAULT NULL,
  freight_terms VARCHAR(60) DEFAULT NULL,
  tracking_no VARCHAR(80) DEFAULT NULL,
  delivery_remarks VARCHAR(255) DEFAULT NULL,
  allow_partial_receipt TINYINT(1) NOT NULL DEFAULT 1,
  inspection_required TINYINT(1) NOT NULL DEFAULT 0,
  payment_terms_template_id INT UNSIGNED DEFAULT NULL,
  payment_terms VARCHAR(120) DEFAULT NULL,
  payment_method VARCHAR(60) DEFAULT NULL,
  payment_due_date_basis ENUM('against_receipt','against_order_date','against_invoice') NOT NULL DEFAULT 'against_invoice',
  advance_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  vendor_bank_details VARCHAR(255) DEFAULT NULL,
  payment_instructions TEXT DEFAULT NULL,
  order_type VARCHAR(40) NOT NULL DEFAULT 'Standard',
  cost_center VARCHAR(120) DEFAULT NULL,
  business_unit VARCHAR(120) DEFAULT NULL,
  approver_id INT UNSIGNED DEFAULT NULL,
  terms_conditions TEXT DEFAULT NULL,
  remarks_internal TEXT DEFAULT NULL,
  tags VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  warehouse_id INT UNSIGNED DEFAULT NULL,
  quantity INT NOT NULL,
  uom VARCHAR(30) NOT NULL DEFAULT 'pcs',
  uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  required_by DATE DEFAULT NULL,
  FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchase Order Taxes & Charges tab rows (mirror of sales_order_taxes).
CREATE TABLE IF NOT EXISTS purchase_order_taxes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id INT UNSIGNED NOT NULL,
  type ENUM('on_item','on_order') NOT NULL DEFAULT 'on_item',
  account_head_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(120) DEFAULT NULL,
  based_on ENUM('net_amount','actual_amount') NOT NULL DEFAULT 'net_amount',
  rate_or_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (account_head_id) REFERENCES ledger_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchase Order Payment Terms tab schedule (mirror of sales_order_payment_schedule).
CREATE TABLE IF NOT EXISTS purchase_order_payment_schedule (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id INT UNSIGNED NOT NULL,
  due_on ENUM('order_date','on_receipt','on_invoice','fixed_days') NOT NULL DEFAULT 'order_date',
  days_from INT NOT NULL DEFAULT 0,
  payment_type ENUM('advance','part_payment','balance') NOT NULL DEFAULT 'balance',
  percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(120) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A Goods Receipt Note (GRN) is what actually adds stock for a purchase
-- (Purchase Orders themselves no longer touch stock). draft = not yet
-- processed, received = stock has been added, cancelled = reversed (only
-- from received).
CREATE TABLE IF NOT EXISTS goods_receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grn_no VARCHAR(30) NOT NULL UNIQUE,
  purchase_order_id INT UNSIGNED DEFAULT NULL,
  vendor_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  posting_date DATE NOT NULL,
  status ENUM('draft','received','cancelled') NOT NULL DEFAULT 'draft',
  notes VARCHAR(255) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- batch_no/manufacturing_date/expiry_date apply when the product has batch
-- tracking on (one batch per GRN line — split across two lines for two
-- batches of the same product). serial_numbers is a raw newline-separated
-- list, entered when the product has serial tracking on; it's parsed into
-- product_serials rows (one per physical unit, count must equal quantity)
-- only at receive time, mirroring how quantity/uom are fixed at draft time
-- but stock_move() itself only fires on the 'received' transition.
CREATE TABLE IF NOT EXISTS goods_receipt_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grn_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL,
  uom VARCHAR(30) NOT NULL DEFAULT 'pcs',
  uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_cost DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  batch_no VARCHAR(60) DEFAULT NULL,
  manufacturing_date DATE DEFAULT NULL,
  expiry_date DATE DEFAULT NULL,
  serial_numbers TEXT DEFAULT NULL,
  FOREIGN KEY (grn_id) REFERENCES goods_receipts(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Accounting
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(30) NOT NULL UNIQUE,
  sales_order_id INT UNSIGNED DEFAULT NULL,
  delivery_note_id INT UNSIGNED DEFAULT NULL,
  customer_id INT UNSIGNED NOT NULL,
  invoice_date DATE NOT NULL,
  due_date DATE DEFAULT NULL,
  status ENUM('unpaid','partially_paid','paid','overdue','cancelled') NOT NULL DEFAULT 'unpaid',
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE SET NULL,
  FOREIGN KEY (delivery_note_id) REFERENCES delivery_notes(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(255) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- method 'credit_note' is only ever written by Sales Return when it posts
-- a credit against this invoice — never selectable on the manual
-- "Record Payment" form.
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_date DATE NOT NULL,
  method ENUM('cash','bank_transfer','card','cheque','other','credit_note','upi') NOT NULL DEFAULT 'cash',
  reference VARCHAR(120) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A vendor bill — billing counterpart to a GRN, mirrors invoices/payments
-- but for money owed to a vendor (accounts payable) rather than money owed
-- by a customer.
CREATE TABLE IF NOT EXISTS purchase_invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pi_no VARCHAR(30) NOT NULL UNIQUE,
  purchase_order_id INT UNSIGNED DEFAULT NULL,
  goods_receipt_id INT UNSIGNED DEFAULT NULL,
  vendor_id INT UNSIGNED NOT NULL,
  invoice_date DATE NOT NULL,
  due_date DATE DEFAULT NULL,
  status ENUM('unpaid','partially_paid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
  FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts(id) ON DELETE SET NULL,
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_invoice_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_invoice_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(255) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- method 'debit_note' is only ever written by Purchase Return when it
-- posts a debit against this bill — never selectable on the manual
-- "Record Payment" form.
CREATE TABLE IF NOT EXISTS purchase_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_date DATE NOT NULL,
  method ENUM('cash','bank_transfer','card','cheque','other','debit_note') NOT NULL DEFAULT 'cash',
  reference VARCHAR(120) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A Sales Return is created against a Sales Order (capped at ordered qty
-- minus whatever's already been returned on it), reverses the stock its
-- Delivery Note took out, and — via invoice_id/credit_amount, set when
-- completed — issues a credit note against the linked Sales Invoice.
CREATE TABLE IF NOT EXISTS sales_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_no VARCHAR(30) NOT NULL UNIQUE,
  sales_order_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED DEFAULT NULL,
  return_date DATE NOT NULL,
  status ENUM('draft','completed','cancelled') NOT NULL DEFAULT 'draft',
  reason VARCHAR(255) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_return_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sales_return_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL,
  uom VARCHAR(30) NOT NULL DEFAULT 'pcs',
  uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_price DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (sales_return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A Purchase Return is created against a Purchase Order (capped at
-- ordered qty minus whatever's already been returned on it), reverses
-- the stock its GRN brought in, and — via purchase_invoice_id/
-- debit_amount, set when completed — issues a debit note against the
-- linked Purchase Invoice.
CREATE TABLE IF NOT EXISTS purchase_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_no VARCHAR(30) NOT NULL UNIQUE,
  purchase_order_id INT UNSIGNED NOT NULL,
  vendor_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  purchase_invoice_id INT UNSIGNED DEFAULT NULL,
  return_date DATE NOT NULL,
  status ENUM('draft','completed','cancelled') NOT NULL DEFAULT 'draft',
  reason VARCHAR(255) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  debit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
  FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_return_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_return_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL,
  uom VARCHAR(30) NOT NULL DEFAULT 'pcs',
  uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_cost DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tracks a POS sale paid via an online payment gateway (Cashfree Payment
-- Links) from QR generation until confirmed paid/failed. The Sales
-- Order/Invoice/stock movement are only created once the gateway
-- confirms payment via webhook - never at QR generation time.
CREATE TABLE IF NOT EXISTS pos_gateway_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(60) NOT NULL UNIQUE,
  gateway VARCHAR(20) NOT NULL DEFAULT 'cashfree',
  gateway_link_id VARCHAR(60) DEFAULT NULL,
  link_url VARCHAR(500) DEFAULT NULL,
  customer_id INT UNSIGNED NOT NULL,
  cart_json TEXT NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  status ENUM('created','paid','failed','expired') NOT NULL DEFAULT 'created',
  sales_order_id INT UNSIGNED DEFAULT NULL,
  last_webhook_payload TEXT DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_no VARCHAR(30) DEFAULT NULL,
  vendor_id INT UNSIGNED DEFAULT NULL,
  payee VARCHAR(150) DEFAULT NULL,
  category VARCHAR(100) NOT NULL,
  account_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL,
  amount DECIMAL(14,2) NOT NULL,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  expense_date DATE NOT NULL,
  payment_method ENUM('cash','bank_transfer','card','cheque','other','upi','auto_debit') NOT NULL DEFAULT 'cash',
  paid_from_account_id INT UNSIGNED DEFAULT NULL,
  reference VARCHAR(120) DEFAULT NULL,
  cost_center_id INT UNSIGNED DEFAULT NULL,
  department_id INT UNSIGNED DEFAULT NULL,
  project VARCHAR(100) DEFAULT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  approved_by INT UNSIGNED DEFAULT NULL,
  approved_at DATETIME DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_expense_no (expense_no),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- HR
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(20) DEFAULT NULL,
  parent_id INT UNSIGNED DEFAULT NULL,
  head_employee_id INT UNSIGNED DEFAULT NULL,
  cost_center VARCHAR(60) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  location VARCHAR(120) DEFAULT NULL,
  default_leave_approver_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_departments_parent FOREIGN KEY (parent_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_departments_leave_approver FOREIGN KEY (default_leave_approver_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_code VARCHAR(30) NOT NULL UNIQUE,
  salutation VARCHAR(10) DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  gender ENUM('male','female','other') DEFAULT NULL,
  date_of_birth DATE DEFAULT NULL,
  marital_status ENUM('single','married','divorced','widowed') DEFAULT NULL,
  blood_group VARCHAR(5) DEFAULT NULL,
  nationality VARCHAR(60) DEFAULT NULL,
  father_or_spouse_name VARCHAR(150) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  personal_email VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  alternate_phone VARCHAR(40) DEFAULT NULL,
  current_address VARCHAR(255) DEFAULT NULL,
  permanent_address VARCHAR(255) DEFAULT NULL,
  city VARCHAR(80) DEFAULT NULL,
  state VARCHAR(80) DEFAULT NULL,
  pincode VARCHAR(12) DEFAULT NULL,
  country VARCHAR(60) DEFAULT NULL,
  emergency_contact_name VARCHAR(150) DEFAULT NULL,
  emergency_contact_relation VARCHAR(60) DEFAULT NULL,
  emergency_contact_phone VARCHAR(40) DEFAULT NULL,
  department_id INT UNSIGNED DEFAULT NULL,
  designation VARCHAR(120) DEFAULT NULL,
  reports_to_id INT UNSIGNED DEFAULT NULL,
  employment_type ENUM('full_time','part_time','contract','intern','apprentice') NOT NULL DEFAULT 'full_time',
  grade VARCHAR(40) DEFAULT NULL,
  work_location VARCHAR(120) DEFAULT NULL,
  work_shift VARCHAR(40) DEFAULT NULL,
  salary DECIMAL(14,2) NOT NULL DEFAULT 0,
  salary_mode ENUM('bank_transfer','cash','cheque') NOT NULL DEFAULT 'bank_transfer',
  bank_name VARCHAR(120) DEFAULT NULL,
  bank_account_holder VARCHAR(150) DEFAULT NULL,
  bank_account_no VARCHAR(40) DEFAULT NULL,
  bank_ifsc VARCHAR(11) DEFAULT NULL,
  pan_no VARCHAR(10) DEFAULT NULL,
  aadhaar_no VARCHAR(12) DEFAULT NULL,
  uan_no VARCHAR(12) DEFAULT NULL,
  pf_no VARCHAR(30) DEFAULT NULL,
  esi_no VARCHAR(20) DEFAULT NULL,
  monthly_gross DECIMAL(14,2) NOT NULL DEFAULT 0,
  monthly_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,
  annual_ctc DECIMAL(14,2) NOT NULL DEFAULT 0,
  resignation_date DATE DEFAULT NULL,
  relieving_date DATE DEFAULT NULL,
  exit_reason VARCHAR(60) DEFAULT NULL,
  exit_notes TEXT DEFAULT NULL,
  remarks TEXT DEFAULT NULL,
  tags VARCHAR(255) DEFAULT NULL,
  hire_date DATE DEFAULT NULL,
  probation_end_date DATE DEFAULT NULL,
  confirmation_date DATE DEFAULT NULL,
  notice_period_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  leave_approver_id INT UNSIGNED DEFAULT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  biometric_id VARCHAR(40) DEFAULT NULL,
  status ENUM('active','inactive','left') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_employees_reports_to FOREIGN KEY (reports_to_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_employees_leave_approver FOREIGN KEY (leave_approver_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- departments.head_employee_id -> employees (added here because employees is created after departments)
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND CONSTRAINT_NAME = 'fk_departments_head');
SET @sql := IF(@fk = 0, 'ALTER TABLE departments ADD CONSTRAINT fk_departments_head FOREIGN KEY (head_employee_id) REFERENCES employees(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS employee_salary_components (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  component_type ENUM('earning','deduction') NOT NULL,
  label VARCHAR(100) NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_education (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  qualification VARCHAR(120) NOT NULL,
  institute VARCHAR(150) DEFAULT NULL,
  year_of_passing SMALLINT UNSIGNED DEFAULT NULL,
  grade VARCHAR(20) DEFAULT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_experience (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  company VARCHAR(150) NOT NULL,
  designation VARCHAR(120) DEFAULT NULL,
  from_date DATE DEFAULT NULL,
  to_date DATE DEFAULT NULL,
  last_salary DECIMAL(14,2) DEFAULT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  annual_allocation DECIMAL(5,1) NOT NULL DEFAULT 0,
  is_paid TINYINT(1) NOT NULL DEFAULT 1,
  allow_half_day TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO leave_types (code, name, annual_allocation, is_paid, sort_order) VALUES
  ('casual', 'Casual Leave', 12, 1, 1),
  ('sick', 'Sick Leave', 12, 1, 2),
  ('annual', 'Earned / Annual Leave', 18, 1, 3),
  ('unpaid', 'Leave Without Pay', 0, 0, 4);

CREATE TABLE IF NOT EXISTS leaves (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_no VARCHAR(30) DEFAULT NULL,
  employee_id INT UNSIGNED NOT NULL,
  leave_type VARCHAR(60) NOT NULL DEFAULT 'casual',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  half_day TINYINT(1) NOT NULL DEFAULT 0,
  half_day_date DATE DEFAULT NULL,
  total_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  reason VARCHAR(255) DEFAULT NULL,
  contact_during_leave VARCHAR(120) DEFAULT NULL,
  handover_to_id INT UNSIGNED DEFAULT NULL,
  leave_approver_id INT UNSIGNED DEFAULT NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  decided_by INT UNSIGNED DEFAULT NULL,
  decided_at DATETIME DEFAULT NULL,
  decision_remarks VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_leaves_handover FOREIGN KEY (handover_to_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_leaves_approver FOREIGN KEY (leave_approver_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_leaves_decided_by FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('present','absent','half_day','leave') NOT NULL DEFAULT 'present',
  shift VARCHAR(40) DEFAULT NULL,
  check_in TIME DEFAULT NULL,
  check_out TIME DEFAULT NULL,
  late_entry TINYINT(1) NOT NULL DEFAULT 0,
  early_exit TINYINT(1) NOT NULL DEFAULT 0,
  working_hours DECIMAL(5,2) DEFAULT NULL,
  leave_id INT UNSIGNED DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  marked_by INT UNSIGNED DEFAULT NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_emp_date (employee_id, attendance_date),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_leave FOREIGN KEY (leave_id) REFERENCES leaves(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS salary_slips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slip_no VARCHAR(30) NOT NULL UNIQUE,
  employee_id INT UNSIGNED NOT NULL,
  pay_period_start DATE NOT NULL,
  pay_period_end DATE NOT NULL,
  posting_date DATE DEFAULT NULL,
  working_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  present_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  paid_leave_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  lop_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  payment_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  prorate_lop TINYINT(1) NOT NULL DEFAULT 1,
  basic_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
  full_basic_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_earnings DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_pay DECIMAL(14,2) NOT NULL DEFAULT 0,
  salary_mode ENUM('bank_transfer','cash','cheque') DEFAULT NULL,
  bank_name VARCHAR(120) DEFAULT NULL,
  bank_account_no VARCHAR(40) DEFAULT NULL,
  bank_ifsc VARCHAR(11) DEFAULT NULL,
  status ENUM('draft','paid') NOT NULL DEFAULT 'draft',
  payment_date DATE DEFAULT NULL,
  payment_method ENUM('cash','bank_transfer','cheque','other') DEFAULT NULL,
  expense_id INT UNSIGNED DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  remarks TEXT DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_emp_period (employee_id, pay_period_start, pay_period_end),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS salary_slip_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  salary_slip_id INT UNSIGNED NOT NULL,
  component_type ENUM('earning','deduction') NOT NULL,
  label VARCHAR(100) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  full_amount DECIMAL(14,2) DEFAULT NULL,
  FOREIGN KEY (salary_slip_id) REFERENCES salary_slips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Print Formats
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS print_formats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  doctype VARCHAR(40) NOT NULL,
  html_template LONGTEXT NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_doctype (doctype),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Stock Entries (Supply Chain): multi-line stock documents that move
-- stock only when submitted. See migration 026 for details.
-- ---------------------------------------------------------------------
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

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------

-- Default admin login: admin@example.com / Admin@123
-- (password_hash for 'Admin@123' using PHP password_hash/BCRYPT)
INSERT INTO users (name, first_name, email, password_hash, role, status) VALUES
('Administrator', 'Administrator', 'admin@example.com', '$2y$12$FGHd5COVc9dRpxaOLavEBeAt1b4DTscuTkJ78Vpr.oojbAyBxH8za', 'admin', 'active');

-- Finance module (see migration 030): cost centers, journal vouchers,
-- bank reconciliation, tax filings, budgets and the report log.
CREATE TABLE IF NOT EXISTS fin_cost_centers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) DEFAULT NULL,
  name VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(255) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fin_journal_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_no VARCHAR(30) NOT NULL UNIQUE,
  voucher_type ENUM('journal','bank_payment','bank_receipt','cash_payment','cash_receipt','contra') NOT NULL DEFAULT 'journal',
  posting_date DATE NOT NULL,
  reference_no VARCHAR(60) DEFAULT NULL,
  reference_date DATE DEFAULT NULL,
  party_name VARCHAR(150) DEFAULT NULL,
  money_account_id INT UNSIGNED DEFAULT NULL,
  cost_center_id INT UNSIGNED DEFAULT NULL,
  project VARCHAR(100) DEFAULT NULL,
  narration VARCHAR(255) DEFAULT NULL,
  total_debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('draft','submitted','cancelled') NOT NULL DEFAULT 'draft',
  remarks TEXT DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  submitted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_journal_date (posting_date),
  FOREIGN KEY (cost_center_id) REFERENCES fin_cost_centers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fin_journal_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  cost_center_id INT UNSIGNED DEFAULT NULL,
  project VARCHAR(100) DEFAULT NULL,
  line_narration VARCHAR(255) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_journal_line_account (account_id),
  FOREIGN KEY (journal_id) REFERENCES fin_journal_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES ledger_accounts(id),
  FOREIGN KEY (cost_center_id) REFERENCES fin_cost_centers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fin_bank_clearances (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_type VARCHAR(20) NOT NULL,
  source_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  cleared_on DATE NOT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_clearance (source_type, source_id, account_id),
  FOREIGN KEY (account_id) REFERENCES ledger_accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fin_tax_filings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category ENUM('gst','tds','pf','esi','professional_tax','income_tax','other') NOT NULL DEFAULT 'gst',
  return_type VARCHAR(40) NOT NULL,
  period_month DATE NOT NULL,
  due_date DATE NOT NULL,
  filing_date DATE DEFAULT NULL,
  status ENUM('pending','filed','paid') NOT NULL DEFAULT 'pending',
  tax_liability DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  payment_date DATE DEFAULT NULL,
  challan_no VARCHAR(60) DEFAULT NULL,
  ack_no VARCHAR(60) DEFAULT NULL,
  remarks VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tax_return_period (return_type, period_month),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fin_budgets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  fiscal_year_start DATE NOT NULL,
  department_id INT UNSIGNED DEFAULT NULL,
  category VARCHAR(100) DEFAULT NULL,
  cost_center_id INT UNSIGNED DEFAULT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  distribution ENUM('equal','custom') NOT NULL DEFAULT 'equal',
  status ENUM('draft','active','closed') NOT NULL DEFAULT 'active',
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (cost_center_id) REFERENCES fin_cost_centers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fin_budget_months (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  budget_id INT UNSIGNED NOT NULL,
  month_index TINYINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_budget_month (budget_id, month_index),
  FOREIGN KEY (budget_id) REFERENCES fin_budgets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fin_report_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_type VARCHAR(30) NOT NULL,
  report_name VARCHAR(150) NOT NULL,
  period_label VARCHAR(80) DEFAULT NULL,
  query_string VARCHAR(255) DEFAULT NULL,
  generated_by INT UNSIGNED DEFAULT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO user_roles (user_id, role_key) VALUES (1, 'system_admin');

INSERT INTO activity_log (entity_type, entity_id, actor_id, action, description) VALUES
('user', 1, 1, 'created', 'Administrator created this');

INSERT INTO settings (setting_key, setting_value) VALUES
('company_name', 'My Company'),
('currency_symbol', '$'),
('currency_code', 'INR'),
('tax_rate', '0');

INSERT INTO price_lists (name, currency, is_default) VALUES
('Standard Selling', 'INR', 1),
('Standard Buying', 'INR', 0);

INSERT INTO ledger_accounts (name, account_type) VALUES
('Output CGST', 'tax'),
('Output SGST', 'tax'),
('Output IGST', 'tax'),
('Freight Charges', 'other'),
('Packaging Charges', 'other'),
('Input CGST', 'tax'),
('Input SGST', 'tax'),
('Input IGST', 'tax'),
('Freight Inward', 'other'),
('Handling Charges', 'other'),
('Loading / Unloading', 'other');

INSERT INTO tax_templates (id, name) VALUES (1, 'GST - Standard (Sales)');
INSERT INTO tax_template_items (tax_template_id, type, account_head_id, description, based_on, rate_or_amount, sort_order) VALUES
(1, 'on_item', 1, 'CGST @ 9%', 'net_amount', 9, 1),
(1, 'on_item', 2, 'SGST @ 9%', 'net_amount', 9, 2);

INSERT INTO payment_terms_templates (id, name) VALUES (1, 'Standard 30 Days');
INSERT INTO payment_terms_template_items (template_id, due_on, days_from, payment_type, percentage, remarks, sort_order) VALUES
(1, 'order_date', 0, 'advance', 30, 'Advance payment', 1),
(1, 'fixed_days', 30, 'balance', 70, 'Balance within 30 days', 2);

INSERT INTO departments (name, description) VALUES
('General', 'Default department');

INSERT INTO categories (name, description) VALUES
('General', 'Default category');

-- Default warehouse hierarchy: an organizational root plus one leaf
-- warehouse that actually holds stock. Add more under "All Warehouses"
-- from Supply Chain → Warehouses as needed.
INSERT INTO warehouses (id, name, parent_id, is_group) VALUES
(1, 'All Warehouses', NULL, 1),
(2, 'Stores', 1, 0);

-- Finance: default cost center, Chart of Accounts groups + system ledgers
-- the derived General Ledger posts to, and Configuration defaults (same as
-- migration 030).
INSERT IGNORE INTO fin_cost_centers (code, name, description) VALUES
('HO', 'Head Office', 'Default cost center');
INSERT INTO ledger_accounts (account_code, name, is_group, root_type, account_type, account_nature, statement_category, system_key) VALUES
('1000', 'Assets',                  1, 'asset',     'current_asset',     'debit',  'balance_sheet', 'grp_assets'),
('1100', 'Current Assets',          1, 'asset',     'current_asset',     'debit',  'balance_sheet', 'grp_current_assets'),
('1110', 'Bank Accounts',           1, 'asset',     'bank',              'debit',  'balance_sheet', 'grp_bank'),
('1200', 'Non-Current Assets',      1, 'asset',     'fixed_asset',       'debit',  'balance_sheet', 'grp_fixed_assets'),
('2000', 'Liabilities',             1, 'liability', 'current_liability', 'credit', 'balance_sheet', 'grp_liabilities'),
('2100', 'Current Liabilities',     1, 'liability', 'current_liability', 'credit', 'balance_sheet', 'grp_current_liabilities'),
('2120', 'Duties & Taxes',          1, 'liability', 'tax',               'credit', 'balance_sheet', 'grp_taxes'),
('2200', 'Non-Current Liabilities', 1, 'liability', 'loan',              'credit', 'balance_sheet', 'grp_loans'),
('3000', 'Equity',                  1, 'equity',    'equity',            'credit', 'balance_sheet', 'grp_equity'),
('4000', 'Income',                  1, 'income',    'income',            'credit', 'profit_loss',   'grp_income'),
('5000', 'Expenses',                1, 'expense',   'expense',           'debit',  'profit_loss',   'grp_expenses'),
('5900', 'Indirect Expenses',       1, 'expense',   'expense',           'debit',  'profit_loss',   'grp_indirect_expenses')
ON DUPLICATE KEY UPDATE
  account_nature     = IF(ledger_accounts.root_type IS NULL, VALUES(account_nature), ledger_accounts.account_nature),
  statement_category = COALESCE(ledger_accounts.statement_category, VALUES(statement_category)),
  root_type          = COALESCE(ledger_accounts.root_type, VALUES(root_type)),
  account_code       = COALESCE(ledger_accounts.account_code, VALUES(account_code)),
  system_key         = COALESCE(ledger_accounts.system_key, VALUES(system_key));

INSERT INTO ledger_accounts (account_code, name, is_group, root_type, account_type, account_nature, statement_category, is_bank, is_cash, system_key) VALUES
('1110-01', 'Primary Bank Account', 0, 'asset',     'bank',               'debit',  'balance_sheet', 1, 0, 'bank'),
('1120',    'Cash in Hand',         0, 'asset',     'cash',               'debit',  'balance_sheet', 0, 1, 'cash'),
('1130',    'Accounts Receivable',  0, 'asset',     'receivable',         'debit',  'balance_sheet', 0, 0, 'receivable'),
('1140',    'Input GST',            0, 'asset',     'tax',                'debit',  'balance_sheet', 0, 0, 'input_tax'),
('2110',    'Accounts Payable',     0, 'liability', 'payable',            'credit', 'balance_sheet', 0, 0, 'payable'),
('2121',    'Output GST Payable',   0, 'liability', 'tax',                'credit', 'balance_sheet', 0, 0, 'output_tax'),
('3100',    'Owner''s Equity',      0, 'equity',    'equity',             'credit', 'balance_sheet', 0, 0, 'equity'),
('3200',    'Opening Balance Equity', 0, 'equity',  'equity',             'credit', 'balance_sheet', 0, 0, 'opening_equity'),
('4100',    'Sales Revenue',        0, 'income',    'income',             'credit', 'profit_loss',   0, 0, 'sales'),
('5100',    'Operating Expenses',   0, 'expense',   'expense',            'debit',  'profit_loss',   0, 0, 'expense'),
('5200',    'Purchases',            0, 'expense',   'cost_of_goods_sold', 'debit',  'profit_loss',   0, 0, 'purchases'),
('5300',    'Salaries & Wages',     0, 'expense',   'expense',            'debit',  'profit_loss',   0, 0, 'payroll')
ON DUPLICATE KEY UPDATE
  account_nature     = IF(ledger_accounts.root_type IS NULL, VALUES(account_nature), ledger_accounts.account_nature),
  statement_category = COALESCE(ledger_accounts.statement_category, VALUES(statement_category)),
  root_type          = COALESCE(ledger_accounts.root_type, VALUES(root_type)),
  account_code       = COALESCE(ledger_accounts.account_code, VALUES(account_code)),
  system_key         = COALESCE(ledger_accounts.system_key, VALUES(system_key));

-- Place the groups and system ledgers in the tree (only where unset).
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_assets'
   SET c.parent_id = p.id WHERE c.system_key IN ('grp_current_assets','grp_fixed_assets') AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_current_assets'
   SET c.parent_id = p.id WHERE c.system_key IN ('grp_bank','cash','receivable','input_tax') AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_bank'
   SET c.parent_id = p.id WHERE c.system_key = 'bank' AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_liabilities'
   SET c.parent_id = p.id WHERE c.system_key IN ('grp_current_liabilities','grp_loans') AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_current_liabilities'
   SET c.parent_id = p.id WHERE c.system_key IN ('payable','grp_taxes') AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_taxes'
   SET c.parent_id = p.id WHERE c.system_key = 'output_tax' AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_equity'
   SET c.parent_id = p.id WHERE c.system_key IN ('equity','opening_equity') AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_income'
   SET c.parent_id = p.id WHERE c.system_key = 'sales' AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_expenses'
   SET c.parent_id = p.id WHERE c.system_key IN ('expense','purchases','payroll','grp_indirect_expenses') AND c.parent_id IS NULL;

-- Existing tax / charge heads from before this migration: file them under
-- the matching group so the tree has no orphans.
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_taxes'
   SET c.parent_id = p.id, c.root_type = 'liability', c.account_nature = 'credit', c.statement_category = 'balance_sheet'
 WHERE c.system_key IS NULL AND c.root_type IS NULL AND c.account_type = 'tax';
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_income'
   SET c.parent_id = p.id, c.root_type = 'income', c.account_nature = 'credit', c.statement_category = 'profit_loss'
 WHERE c.system_key IS NULL AND c.root_type IS NULL AND c.account_type = 'income';
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_indirect_expenses'
   SET c.parent_id = p.id, c.root_type = 'expense', c.account_nature = 'debit', c.statement_category = 'profit_loss'
 WHERE c.system_key IS NULL AND c.root_type IS NULL AND c.account_type IN ('expense','other');

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('fin_fy_start_month', '4'),
('fin_tds_applicable', '1'),
('fin_default_cost_center_id', ''),
('fin_expense_approval_limit', '0'),
('fin_prefix_journal', 'JV'),
('fin_prefix_bank_payment', 'BP'),
('fin_prefix_bank_receipt', 'BR'),
('fin_prefix_cash_payment', 'CP'),
('fin_prefix_cash_receipt', 'CR'),
('fin_prefix_contra', 'CT'),
('fin_prefix_expense', 'EXP'),
('fin_number_padding', '4');
