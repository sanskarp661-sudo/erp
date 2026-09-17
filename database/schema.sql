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

CREATE TABLE IF NOT EXISTS stock_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  type ENUM('in','out','adjustment') NOT NULL,
  quantity INT NOT NULL,
  reference VARCHAR(120) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
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

-- ---------------------------------------------------------------------
-- Accounting
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(30) NOT NULL UNIQUE,
  sales_order_id INT UNSIGNED DEFAULT NULL,
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

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------

-- Default admin login: admin@example.com / Admin@123
-- (password_hash for 'Admin@123' using PHP password_hash/BCRYPT)
INSERT INTO users (name, first_name, email, password_hash, role, status) VALUES
('Administrator', 'Administrator', 'admin@example.com', '$2y$12$FGHd5COVc9dRpxaOLavEBeAt1b4DTscuTkJ78Vpr.oojbAyBxH8za', 'admin', 'active');

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
