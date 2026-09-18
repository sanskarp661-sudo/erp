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

-- ---------------------------------------------------------------------
-- Inventory
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
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
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  order_date DATE NOT NULL,
  status ENUM('pending','confirmed','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
  notes VARCHAR(255) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_date DATE NOT NULL,
  method ENUM('cash','bank_transfer','card','cheque','other') NOT NULL DEFAULT 'cash',
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

CREATE TABLE IF NOT EXISTS purchase_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_date DATE NOT NULL,
  method ENUM('cash','bank_transfer','card','cheque','other') NOT NULL DEFAULT 'cash',
  reference VARCHAR(120) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
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
('tax_rate', '0');

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
