-- Migration 031: POS module rebuild (10X YOU POS, separate screens).
--
-- Adds what the new POS screens need on top of the existing Sales Order /
-- Invoice / Payment flow (a POS sale still creates all three, exactly as
-- before, so it keeps showing up in Sales and Finance):
--   * pos_profiles     - terminals (POS-01, POS-02...), each tied to a store
--                        (warehouse) and price list.
--   * pos_shifts       - opening & closing shift with cash reconciliation.
--   * pos_held_orders  - carts parked with "Hold" and resumed later.
--   * sales_orders     - POS invoice number (POS-2026-000123), terminal,
--                        shift, discount / tax / round-off breakdown.
--   * sales_order_items- per-line discount amount, GST rate, tax, line total.
--   * payments         - 'wallet' and 'store_credit' methods, shift link.
--   * sales_returns    - shift link, refund method, exchange-credit status.
--
-- Safe to re-run: every CREATE / ADD COLUMN / INDEX is IF NOT EXISTS and
-- the seed + backfill only touch rows that are still unset.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pos_profiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  warehouse_id INT UNSIGNED DEFAULT NULL,
  price_list_id INT UNSIGNED DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pos_shifts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shift_no VARCHAR(30) NOT NULL UNIQUE,
  pos_profile_id INT UNSIGNED DEFAULT NULL,
  warehouse_id INT UNSIGNED DEFAULT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_by INT UNSIGNED DEFAULT NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  closed_by INT UNSIGNED DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  expected_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  counted_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  difference DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_returns DECIMAL(14,2) NOT NULL DEFAULT 0,
  opening_remarks VARCHAR(255) DEFAULT NULL,
  remarks VARCHAR(255) DEFAULT NULL,
  INDEX idx_pos_shift_status (pos_profile_id, status),
  FOREIGN KEY (pos_profile_id) REFERENCES pos_profiles(id) ON DELETE SET NULL,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pos_held_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hold_no VARCHAR(30) NOT NULL UNIQUE,
  pos_profile_id INT UNSIGNED DEFAULT NULL,
  warehouse_id INT UNSIGNED DEFAULT NULL,
  customer_id INT UNSIGNED DEFAULT NULL,
  cart_json MEDIUMTEXT NOT NULL,
  items_count INT NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (pos_profile_id) REFERENCES pos_profiles(id) ON DELETE SET NULL,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sales_orders
  ADD COLUMN IF NOT EXISTS pos_no VARCHAR(30) DEFAULT NULL AFTER channel,
  ADD COLUMN IF NOT EXISTS pos_profile_id INT UNSIGNED DEFAULT NULL AFTER pos_no,
  ADD COLUMN IF NOT EXISTS pos_shift_id INT UNSIGNED DEFAULT NULL AFTER pos_profile_id,
  ADD COLUMN IF NOT EXISTS item_discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS round_off DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS amount_received DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS change_amount DECIMAL(14,2) NOT NULL DEFAULT 0;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_sales_orders_pos_no ON sales_orders (pos_no);
CREATE INDEX IF NOT EXISTS idx_sales_orders_pos_shift ON sales_orders (pos_shift_id);

ALTER TABLE sales_order_items
  ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS tax_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS line_total DECIMAL(14,2) NOT NULL DEFAULT 0;

ALTER TABLE payments MODIFY COLUMN method ENUM('cash','bank_transfer','card','cheque','other','credit_note','upi','wallet','store_credit') NOT NULL DEFAULT 'cash';
ALTER TABLE payments ADD COLUMN IF NOT EXISTS pos_shift_id INT UNSIGNED DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_payments_pos_shift ON payments (pos_shift_id);

ALTER TABLE sales_returns
  ADD COLUMN IF NOT EXISTS pos_shift_id INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS refund_method VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS exchange_status ENUM('none','open','used','refunded') NOT NULL DEFAULT 'none',
  ADD COLUMN IF NOT EXISTS exchange_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS exchange_order_id INT UNSIGNED DEFAULT NULL;

-- One default terminal on the first active store, so POS works right away.
INSERT INTO pos_profiles (name, warehouse_id, price_list_id)
SELECT 'POS-01',
       (SELECT id FROM warehouses WHERE is_group = 0 AND status = 'active' ORDER BY id LIMIT 1),
       (SELECT id FROM price_lists WHERE is_default = 1 ORDER BY id LIMIT 1)
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM pos_profiles);

-- Give earlier POS sales a POS invoice number (their id keeps it unique).
UPDATE sales_orders
   SET pos_no = CONCAT('POS-', YEAR(order_date), '-', LPAD(id, 6, '0'))
 WHERE channel = 'pos' AND pos_no IS NULL;

-- Earlier POS lines had no discount or tax, so their line total is the subtotal.
UPDATE sales_order_items soi JOIN sales_orders so ON so.id = soi.order_id
   SET soi.line_total = soi.subtotal
 WHERE so.channel = 'pos' AND soi.line_total = 0;
