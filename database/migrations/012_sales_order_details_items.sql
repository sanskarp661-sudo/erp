-- Migration 012: Sales Order redesign, Phase 1 (Details + Items tabs).
--
-- Rebuilds the Sales Order into a tabbed ERPNext-style document. This
-- migration covers only the Details and Items tabs — where every
-- mandatory field lives (Customer, Order Date, Price List, Currency,
-- Item Code, Warehouse, Qty, UOM, Rate). Taxes & Charges, Shipping &
-- Delivery, Payment Terms, More Info and Documents tabs land in later
-- migrations.
--
-- New masters:
--   price_lists / price_list_items — a product's rate on a given price
--     list; if a product has no explicit row, the form falls back to
--     products.selling_price so "Standard Selling" works without every
--     product needing to be priced twice.
--   customer_addresses — customers.address stays as the single legacy
--     free-text field every existing page already reads; this is an
--     additive multi-address book, only used by the new SO Customer
--     Address dropdown.
--
-- sales_orders gains header fields for the Details tab. sales_order_items
-- gains uom, warehouse_id (per-line — items can ship from different
-- warehouses, as in the mockup), description, discount_percent.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS price_lists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  currency VARCHAR(3) NOT NULL DEFAULT 'INR',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO price_lists (name, currency, is_default)
SELECT * FROM (SELECT 'Standard Selling' name, 'INR' currency, 1 is_default) t
WHERE NOT EXISTS (SELECT 1 FROM price_lists WHERE name = 'Standard Selling');

CREATE TABLE IF NOT EXISTS price_list_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  price_list_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_pricelist_product (price_list_id, product_id),
  FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS contact_person VARCHAR(120) DEFAULT NULL AFTER customer_id;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS customer_address_id INT UNSIGNED DEFAULT NULL AFTER contact_person;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS required_delivery_date DATE DEFAULT NULL AFTER order_date;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS price_list_id INT UNSIGNED DEFAULT NULL AFTER required_delivery_date;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS currency VARCHAR(3) NOT NULL DEFAULT 'INR' AFTER price_list_id;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS sales_channel VARCHAR(60) DEFAULT NULL AFTER currency;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS territory VARCHAR(120) DEFAULT NULL AFTER sales_channel;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS sales_person_id INT UNSIGNED DEFAULT NULL AFTER territory;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS customer_po_no VARCHAR(60) DEFAULT NULL AFTER sales_person_id;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS project VARCHAR(120) DEFAULT NULL AFTER customer_po_no;

ALTER TABLE sales_order_items ADD COLUMN IF NOT EXISTS description VARCHAR(255) DEFAULT NULL AFTER product_id;
ALTER TABLE sales_order_items ADD COLUMN IF NOT EXISTS warehouse_id INT UNSIGNED DEFAULT NULL AFTER description;
ALTER TABLE sales_order_items ADD COLUMN IF NOT EXISTS uom VARCHAR(30) NOT NULL DEFAULT 'pcs' AFTER quantity;
ALTER TABLE sales_order_items ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER unit_price;

-- Backfill existing rows so old data displays sensibly in the new UI.
UPDATE sales_order_items soi
JOIN products p ON p.id = soi.product_id
SET soi.uom = p.unit
WHERE soi.uom = 'pcs';

UPDATE sales_order_items soi
JOIN sales_orders so ON so.id = soi.order_id
SET soi.warehouse_id = so.warehouse_id
WHERE soi.warehouse_id IS NULL AND so.warehouse_id IS NOT NULL;

UPDATE sales_orders SET price_list_id = (SELECT id FROM price_lists WHERE name = 'Standard Selling' LIMIT 1)
WHERE price_list_id IS NULL;

INSERT INTO settings (setting_key, setting_value)
SELECT 'currency_code', 'INR' WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'currency_code');
