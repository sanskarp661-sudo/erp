-- Migration 018: Item Master redesign, Phase 1 (Details tab).
--
-- Rebuilds the Item (Product) record as a tabbed ERPNext-style document,
-- mirroring the Sales Order redesign. This phase adds:
--   - Brands and Item Categories master tables (Item Group itself stays
--     mapped to the existing `categories` table/`category_id` column —
--     no need to duplicate it).
--   - product_barcodes: an item's barcode list (# Barcode/Type/UOM/Default).
--   - A large batch of new nullable columns on `products` for the Details
--     tab's Basic Information / Quick Settings / Default Units /
--     Item Defaults / Additional Details cards.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

SET NAMES utf8mb4;

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

ALTER TABLE products ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL AFTER category_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS item_category_id INT UNSIGNED DEFAULT NULL AFTER description;
ALTER TABLE products ADD COLUMN IF NOT EXISTS brand_id INT UNSIGNED DEFAULT NULL AFTER item_category_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS hsn_sac_code VARCHAR(20) DEFAULT NULL AFTER brand_id;

ALTER TABLE products ADD COLUMN IF NOT EXISTS is_stock_item TINYINT(1) NOT NULL DEFAULT 1 AFTER hsn_sac_code;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_sales_item TINYINT(1) NOT NULL DEFAULT 1 AFTER is_stock_item;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_purchase_item TINYINT(1) NOT NULL DEFAULT 1 AFTER is_sales_item;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_manufactured_item TINYINT(1) NOT NULL DEFAULT 0 AFTER is_purchase_item;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_sub_contracted_item TINYINT(1) NOT NULL DEFAULT 0 AFTER is_manufactured_item;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_asset_item TINYINT(1) NOT NULL DEFAULT 0 AFTER is_sub_contracted_item;
ALTER TABLE products ADD COLUMN IF NOT EXISTS has_variants TINYINT(1) NOT NULL DEFAULT 0 AFTER is_asset_item;

ALTER TABLE products ADD COLUMN IF NOT EXISTS purchase_uom VARCHAR(30) DEFAULT NULL AFTER has_variants;
ALTER TABLE products ADD COLUMN IF NOT EXISTS sales_uom VARCHAR(30) DEFAULT NULL AFTER purchase_uom;
ALTER TABLE products ADD COLUMN IF NOT EXISTS purchase_uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000 AFTER sales_uom;
ALTER TABLE products ADD COLUMN IF NOT EXISTS sales_uom_conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000 AFTER purchase_uom_conversion_factor;

ALTER TABLE products ADD COLUMN IF NOT EXISTS default_warehouse_id INT UNSIGNED DEFAULT NULL AFTER sales_uom_conversion_factor;
ALTER TABLE products ADD COLUMN IF NOT EXISTS default_price_list_id INT UNSIGNED DEFAULT NULL AFTER default_warehouse_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS min_stock_level INT NOT NULL DEFAULT 0 AFTER default_price_list_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS max_stock_level INT NOT NULL DEFAULT 0 AFTER min_stock_level;
ALTER TABLE products ADD COLUMN IF NOT EXISTS lead_time_days INT NOT NULL DEFAULT 0 AFTER max_stock_level;
ALTER TABLE products ADD COLUMN IF NOT EXISTS shelf_life_days INT NOT NULL DEFAULT 0 AFTER lead_time_days;

ALTER TABLE products ADD COLUMN IF NOT EXISTS item_type ENUM('Finished Good','Raw Material','Service Item','Consumable') NOT NULL DEFAULT 'Finished Good' AFTER shelf_life_days;
ALTER TABLE products ADD COLUMN IF NOT EXISTS valuation_method ENUM('FIFO','LIFO','Moving Average') NOT NULL DEFAULT 'FIFO' AFTER item_type;
ALTER TABLE products ADD COLUMN IF NOT EXISTS standard_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER valuation_method;
ALTER TABLE products ADD COLUMN IF NOT EXISTS standard_volume_ltr DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER standard_weight_kg;
ALTER TABLE products ADD COLUMN IF NOT EXISTS gross_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER standard_volume_ltr;
ALTER TABLE products ADD COLUMN IF NOT EXISTS net_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER gross_weight_kg;
ALTER TABLE products ADD COLUMN IF NOT EXISTS tags VARCHAR(255) DEFAULT NULL AFTER net_weight_kg;
