-- Migration 008: Item Master image.
--
-- Adds an optional product photo, shown on the new Item Master detail
-- page (inventory/product_view.php) alongside its Connections (linked
-- Sales/Purchase Orders, Delivery Notes, GRNs, Returns, Invoices, Stock
-- Movements) and Trends (monthly sales/purchase charts). The uploaded
-- file itself lives outside the repo at uploads/products/ — this column
-- only stores its relative path.
--
-- Safe to re-run: the ADD COLUMN below is idempotent (IF NOT EXISTS).

SET NAMES utf8mb4;

ALTER TABLE products ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT NULL AFTER name;
