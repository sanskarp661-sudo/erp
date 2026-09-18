-- Migration 007: Sales/Purchase Returns, Stock Balance reservation.
--
-- Adds a return flow to both sides of the business:
--   Sales Return   — created against a Sales Order, reverses the stock a
--                    Delivery Note took out, and (when there's an unpaid
--                    balance) issues a credit note against the linked
--                    Sales Invoice by crediting its amount_paid.
--   Purchase Return — created against a Purchase Order, reverses the
--                    stock a GRN brought in, and issues a debit note
--                    against the linked Purchase Invoice the same way.
--
-- Also adds an optional warehouse_id on Sales Orders, used only for the
-- new Stock Balance Report's "Reserved Qty" column (orders committed to a
-- warehouse but not yet fulfilled by a delivered Delivery Note) — it does
-- NOT cause a Sales Order to move stock; that's still exclusively a
-- Delivery Note's job.
--
-- Safe to re-run: every CREATE TABLE and ADD COLUMN below is idempotent
-- (IF NOT EXISTS guards), and the ENUM widenings are plain redefinitions
-- that are harmless to repeat.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Sales Return
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- Purchase Return
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- Credit note / debit note payment methods (used only for the system-
-- generated ledger rows Sales/Purchase Return post when completed or
-- cancelled — never selectable on the manual "Record Payment" form).
-- ---------------------------------------------------------------------
ALTER TABLE payments MODIFY COLUMN method ENUM('cash','bank_transfer','card','cheque','other','credit_note') NOT NULL DEFAULT 'cash';
ALTER TABLE purchase_payments MODIFY COLUMN method ENUM('cash','bank_transfer','card','cheque','other','debit_note') NOT NULL DEFAULT 'cash';

-- ---------------------------------------------------------------------
-- Optional warehouse on Sales Order, for Reserved Qty reporting only.
-- ---------------------------------------------------------------------
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS warehouse_id INT UNSIGNED DEFAULT NULL AFTER customer_id;
