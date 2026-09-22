-- Migration 024: Item Master redesign, Phase 7 (Batch & Serial No. tab +
-- GRN receiving wiring).
--
-- The product-level flags (has_batch_no/has_serial_no/batch_expiry_required/
-- batch_number_series) already existed from an earlier phase (migration
-- 019) as inert configuration — nothing ever read them. This migration adds
-- the actual data infrastructure and wires GRN receiving to use it:
--
-- product_batches: one row per batch/lot ever received for a batch-tracked
-- item, found-or-created by (product_id, batch_no) the first time that
-- batch number is received on a GRN. Stays on record even at zero stock,
-- for traceability.
--
-- product_serials: one row per physical serialized unit ever received.
-- grn_item_id has no FK (added as a plain column — see the same
-- forward-reference convention used for schema.sql's late-declared tables).
--
-- stock_bins gets a nullable batch_id (NULL for non-batch-tracked stock)
-- and its unique key becomes (product_id, warehouse_id, batch_id) so the
-- same product+warehouse can hold separate per-batch balances.
-- stock_movements gets the same nullable batch_id for its log rows.
--
-- goods_receipt_items gets batch_no/manufacturing_date/expiry_date (one
-- batch per GRN line — split across two lines for two batches of the same
-- product) and a raw newline-separated serial_numbers column, parsed into
-- product_serials rows only at receive time (mirrors how quantity/uom are
-- fixed at draft time but stock_move() only fires on the 'received'
-- transition).
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent. The
-- unique-key change on stock_bins is guarded to only run once.

SET NAMES utf8mb4;

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

CREATE TABLE IF NOT EXISTS product_serials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  serial_no VARCHAR(80) NOT NULL,
  batch_id INT UNSIGNED DEFAULT NULL,
  warehouse_id INT UNSIGNED DEFAULT NULL,
  status ENUM('in_stock','issued','damaged') NOT NULL DEFAULT 'in_stock',
  grn_item_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  UNIQUE KEY uq_product_serial (product_id, serial_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE stock_bins ADD COLUMN IF NOT EXISTS batch_id INT UNSIGNED DEFAULT NULL AFTER warehouse_id;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_bins' AND CONSTRAINT_NAME = 'stock_bins_ibfk_batch'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE stock_bins ADD CONSTRAINT stock_bins_ibfk_batch FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @old_key_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_bins' AND INDEX_NAME = 'uniq_product_warehouse'
);
SET @sql = IF(@old_key_exists > 0,
  'ALTER TABLE stock_bins DROP INDEX uniq_product_warehouse, ADD UNIQUE KEY uniq_product_warehouse_batch (product_id, warehouse_id, batch_id)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS batch_id INT UNSIGNED DEFAULT NULL AFTER warehouse_id;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND CONSTRAINT_NAME = 'stock_movements_ibfk_batch'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_ibfk_batch FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE goods_receipt_items ADD COLUMN IF NOT EXISTS batch_no VARCHAR(60) DEFAULT NULL AFTER subtotal;
ALTER TABLE goods_receipt_items ADD COLUMN IF NOT EXISTS manufacturing_date DATE DEFAULT NULL AFTER batch_no;
ALTER TABLE goods_receipt_items ADD COLUMN IF NOT EXISTS expiry_date DATE DEFAULT NULL AFTER manufacturing_date;
ALTER TABLE goods_receipt_items ADD COLUMN IF NOT EXISTS serial_numbers TEXT DEFAULT NULL AFTER expiry_date;
