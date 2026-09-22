-- Migration 023: Item Master redesign, Phase 6 (Units of Measure tab +
-- real conversion wiring).
--
-- Adds product_uoms — the real per-item alternate-UOM list (uom name +
-- conversion factor to the item's own stock unit) shown on the new Item
-- Master "Units of Measure" tab. This is distinct from the existing
-- products.purchase_uom/sales_uom columns (Phase 1), which just remember
-- which single UOM a product's Purchase/Sales tab defaults to — this
-- table is the full list a line item can actually choose from.
--
-- Every stock-touching (and stock-adjacent) line-item table gets its own
-- uom + uom_conversion_factor columns, snapshotted at save time from the
-- item's product_uoms list (or 1.000 if the line uses the product's own
-- stock unit) so a document's stock effect never silently changes if the
-- item's UOM table is edited later. sales_order_items already had a
-- cosmetic `uom` column from an earlier project (no conversion factor
-- behind it, and silently dropped when a Delivery Note was created from
-- the order) — this migration gives it a real conversion_factor and every
-- other line-item table (quotation, PO, GRN, DN, both Returns) the same
-- uom + uom_conversion_factor pair.
--
-- Existing rows across all six newly-uom'd tables are backfilled to the
-- product's own stock unit with factor 1.000 (the only quantity meaning
-- they could have had before this migration existed).
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent. The
-- backfill UPDATE is safe to re-run too (same historical-backfill pattern
-- as migration 006) as long as nothing else has changed those rows'
-- uom since this migration first ran.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS product_uoms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  uom VARCHAR(30) NOT NULL,
  conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_uom (product_id, uom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sales_order_items ADD COLUMN IF NOT EXISTS uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000 AFTER uom;

ALTER TABLE quotation_items ADD COLUMN IF NOT EXISTS uom VARCHAR(30) NOT NULL DEFAULT 'pcs' AFTER quantity;
ALTER TABLE quotation_items ADD COLUMN IF NOT EXISTS uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000 AFTER uom;

ALTER TABLE delivery_note_items ADD COLUMN IF NOT EXISTS uom VARCHAR(30) NOT NULL DEFAULT 'pcs' AFTER quantity;
ALTER TABLE delivery_note_items ADD COLUMN IF NOT EXISTS uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000 AFTER uom;

ALTER TABLE purchase_order_items ADD COLUMN IF NOT EXISTS uom VARCHAR(30) NOT NULL DEFAULT 'pcs' AFTER quantity;
ALTER TABLE purchase_order_items ADD COLUMN IF NOT EXISTS uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000 AFTER uom;

ALTER TABLE goods_receipt_items ADD COLUMN IF NOT EXISTS uom VARCHAR(30) NOT NULL DEFAULT 'pcs' AFTER quantity;
ALTER TABLE goods_receipt_items ADD COLUMN IF NOT EXISTS uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000 AFTER uom;

ALTER TABLE sales_return_items ADD COLUMN IF NOT EXISTS uom VARCHAR(30) NOT NULL DEFAULT 'pcs' AFTER quantity;
ALTER TABLE sales_return_items ADD COLUMN IF NOT EXISTS uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000 AFTER uom;

ALTER TABLE purchase_return_items ADD COLUMN IF NOT EXISTS uom VARCHAR(30) NOT NULL DEFAULT 'pcs' AFTER quantity;
ALTER TABLE purchase_return_items ADD COLUMN IF NOT EXISTS uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000 AFTER uom;

UPDATE quotation_items qi JOIN products p ON p.id = qi.product_id SET qi.uom = p.unit;
UPDATE delivery_note_items dni JOIN products p ON p.id = dni.product_id SET dni.uom = p.unit;
UPDATE purchase_order_items poi JOIN products p ON p.id = poi.product_id SET poi.uom = p.unit;
UPDATE goods_receipt_items gri JOIN products p ON p.id = gri.product_id SET gri.uom = p.unit;
UPDATE sales_return_items sri JOIN products p ON p.id = sri.product_id SET sri.uom = p.unit;
UPDATE purchase_return_items pri JOIN products p ON p.id = pri.product_id SET pri.uom = p.unit;
