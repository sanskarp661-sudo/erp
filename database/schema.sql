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

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  image VARCHAR(255) DEFAULT NULL,
  category_id INT UNSIGNED DEFAULT NULL,
  unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
  cost_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  selling_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  quantity INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
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

-- Per-(product, warehouse) stock balance. products.quantity is kept as a
-- maintained total across all warehouses — see includes/stock.php.
CREATE TABLE IF NOT EXISTS stock_bins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_product_warehouse (product_id, warehouse_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED DEFAULT NULL,
  type ENUM('in','out','adjustment') NOT NULL,
  quantity INT NOT NULL,
  reference VARCHAR(120) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Sales / CRM
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  company VARCHAR(150) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  credit_limit DECIMAL(14,2) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
  name VARCHAR(120) NOT NULL UNIQUE,
  account_type ENUM('tax','income','expense','other') NOT NULL DEFAULT 'other',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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

CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_no VARCHAR(30) NOT NULL UNIQUE,
  vendor_id INT UNSIGNED NOT NULL,
  order_date DATE NOT NULL,
  status ENUM('pending','ordered','received','cancelled') NOT NULL DEFAULT 'pending',
  notes VARCHAR(255) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL,
  unit_cost DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS goods_receipt_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grn_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL,
  unit_cost DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
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
  category VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  amount DECIMAL(14,2) NOT NULL,
  expense_date DATE NOT NULL,
  payment_method ENUM('cash','bank_transfer','card','cheque','other') NOT NULL DEFAULT 'cash',
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- HR
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  department_id INT UNSIGNED DEFAULT NULL,
  designation VARCHAR(120) DEFAULT NULL,
  salary DECIMAL(14,2) NOT NULL DEFAULT 0,
  hire_date DATE DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('present','absent','half_day','leave') NOT NULL DEFAULT 'present',
  check_in TIME DEFAULT NULL,
  check_out TIME DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY uniq_emp_date (employee_id, attendance_date),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leaves (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  leave_type VARCHAR(60) NOT NULL DEFAULT 'casual',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS salary_slips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slip_no VARCHAR(30) NOT NULL UNIQUE,
  employee_id INT UNSIGNED NOT NULL,
  pay_period_start DATE NOT NULL,
  pay_period_end DATE NOT NULL,
  basic_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_earnings DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_pay DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('draft','paid') NOT NULL DEFAULT 'draft',
  payment_date DATE DEFAULT NULL,
  payment_method ENUM('cash','bank_transfer','cheque','other') DEFAULT NULL,
  expense_id INT UNSIGNED DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
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

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------

-- Default admin login: admin@example.com / Admin@123
-- (password_hash for 'Admin@123' using PHP password_hash/BCRYPT)
INSERT INTO users (name, first_name, email, password_hash, role, status) VALUES
('Administrator', 'Administrator', 'admin@example.com', '$2y$12$FGHd5COVc9dRpxaOLavEBeAt1b4DTscuTkJ78Vpr.oojbAyBxH8za', 'admin', 'active');

INSERT INTO user_roles (user_id, role_key) VALUES (1, 'system_admin');

INSERT INTO activity_log (entity_type, entity_id, actor_id, action, description) VALUES
('user', 1, 1, 'created', 'Administrator created this');

INSERT INTO settings (setting_key, setting_value) VALUES
('company_name', 'My Company'),
('currency_symbol', '$'),
('currency_code', 'INR'),
('tax_rate', '0');

INSERT INTO price_lists (name, currency, is_default) VALUES
('Standard Selling', 'INR', 1);

INSERT INTO ledger_accounts (name, account_type) VALUES
('Output CGST', 'tax'),
('Output SGST', 'tax'),
('Output IGST', 'tax'),
('Freight Charges', 'other'),
('Packaging Charges', 'other');

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
