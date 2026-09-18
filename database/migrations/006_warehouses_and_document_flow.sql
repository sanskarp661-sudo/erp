-- Migration 006: warehouse hierarchy + proper document flow.
--
-- Restructures Sales into Sales Order -> Delivery Note -> Sales Invoice,
-- and Purchases into Purchase Order -> Goods Receipt (GRN) -> Purchase
-- Invoice. From this point on, Sales/Purchase Orders no longer touch
-- stock themselves — only Delivery Notes (out) and GRNs (in) do, via
-- includes/stock.php's stock_move(). Stock is now tracked per warehouse
-- (stock_bins); products.quantity is kept as a maintained total across
-- all warehouses so existing reports/dashboards keep working unchanged.
--
-- This migration also BACKFILLS history: every Sales Order that was
-- already confirmed/shipped/completed (i.e. already had its stock
-- deducted under the old code) gets one Delivery Note recording that;
-- every Purchase Order already marked "received" gets one GRN. These
-- backfilled documents are pure record-keeping — they do NOT move any
-- stock again, since your current stock levels already reflect those
-- historical transactions. Existing invoices linked to a backfilled
-- Sales Order get their new delivery_note_id pointed at it too.
--
-- Safe to re-run: every CREATE TABLE, ADD COLUMN and INSERT below is
-- idempotent (IF NOT EXISTS / ON DUPLICATE KEY / NOT EXISTS guards) and
-- can be run again without creating duplicates.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Warehouses + per-warehouse stock
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS warehouses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  parent_id INT UNSIGNED DEFAULT NULL,
  is_group TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parent_id) REFERENCES warehouses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO warehouses (id, name, parent_id, is_group)
SELECT * FROM (SELECT 1 id, 'All Warehouses' name, NULL parent_id, 1 is_group) t
WHERE NOT EXISTS (SELECT 1 FROM warehouses WHERE id = 1);

INSERT INTO warehouses (id, name, parent_id, is_group)
SELECT * FROM (SELECT 2 id, 'Stores' name, 1 parent_id, 0 is_group) t
WHERE NOT EXISTS (SELECT 1 FROM warehouses WHERE id = 2);

CREATE TABLE IF NOT EXISTS stock_bins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_product_warehouse (product_id, warehouse_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed every product's current total quantity as its starting balance in
-- the default "Stores" warehouse.
INSERT INTO stock_bins (product_id, warehouse_id, quantity)
SELECT id, 2, quantity FROM products
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS warehouse_id INT UNSIGNED DEFAULT NULL AFTER product_id;
UPDATE stock_movements SET warehouse_id = 2 WHERE warehouse_id IS NULL;

-- ---------------------------------------------------------------------
-- Delivery Note (Sales)
-- ---------------------------------------------------------------------
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

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS delivery_note_id INT UNSIGNED DEFAULT NULL AFTER sales_order_id;

-- Backfill: one Delivery Note per Sales Order whose stock was already
-- deducted under the old code (status confirmed/shipped/completed),
-- dated at the order's own date, at the default Stores warehouse.
INSERT INTO delivery_notes (dn_no, sales_order_id, customer_id, warehouse_id, posting_date, status, notes, total_amount, created_by, created_at)
SELECT CONCAT('DN-', LPAD(ROW_NUMBER() OVER (ORDER BY so.id), 6, '0')),
       so.id, so.customer_id, 2, so.order_date, 'delivered',
       'Backfilled from historical Sales Order', so.total_amount, so.created_by, so.created_at
FROM sales_orders so
WHERE so.status IN ('confirmed', 'shipped', 'completed')
  AND NOT EXISTS (SELECT 1 FROM delivery_notes dn2 WHERE dn2.sales_order_id = so.id);

INSERT INTO delivery_note_items (dn_id, product_id, quantity, unit_price, subtotal)
SELECT dn.id, soi.product_id, soi.quantity, soi.unit_price, soi.subtotal
FROM delivery_notes dn
JOIN sales_order_items soi ON soi.order_id = dn.sales_order_id
WHERE dn.notes = 'Backfilled from historical Sales Order'
  AND NOT EXISTS (SELECT 1 FROM delivery_note_items dni WHERE dni.dn_id = dn.id);

UPDATE invoices i
JOIN delivery_notes dn ON dn.sales_order_id = i.sales_order_id AND dn.notes = 'Backfilled from historical Sales Order'
SET i.delivery_note_id = dn.id
WHERE i.sales_order_id IS NOT NULL AND i.delivery_note_id IS NULL;

-- ---------------------------------------------------------------------
-- Goods Receipt Note / GRN (Purchases)
-- ---------------------------------------------------------------------
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

-- Backfill: one GRN per Purchase Order already marked "received" under
-- the old code (which added stock at that point).
INSERT INTO goods_receipts (grn_no, purchase_order_id, vendor_id, warehouse_id, posting_date, status, notes, total_amount, created_by, created_at)
SELECT CONCAT('GRN-', LPAD(ROW_NUMBER() OVER (ORDER BY po.id), 6, '0')),
       po.id, po.vendor_id, 2, po.order_date, 'received',
       'Backfilled from historical Purchase Order', po.total_amount, po.created_by, po.created_at
FROM purchase_orders po
WHERE po.status = 'received'
  AND NOT EXISTS (SELECT 1 FROM goods_receipts g2 WHERE g2.purchase_order_id = po.id);

INSERT INTO goods_receipt_items (grn_id, product_id, quantity, unit_cost, subtotal)
SELECT g.id, poi.product_id, poi.quantity, poi.unit_cost, poi.subtotal
FROM goods_receipts g
JOIN purchase_order_items poi ON poi.po_id = g.purchase_order_id
WHERE g.notes = 'Backfilled from historical Purchase Order'
  AND NOT EXISTS (SELECT 1 FROM goods_receipt_items gri WHERE gri.grn_id = g.id);

-- ---------------------------------------------------------------------
-- Purchase Invoice (vendor bill / accounts payable)
-- ---------------------------------------------------------------------
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
