-- Migration 019: Item Master redesign, Phase 2 (Inventory tab).
--
-- Adds Stock Settings / Inventory Dimensions / Stock Levels / Storage &
-- Shelf / Additional Inventory Options fields, plus the core batch/serial
-- toggle fields the Inventory tab's compact "Batch & Serial Settings"
-- card needs (the FULL batch/serial configuration — expiry rules, serial
-- number formats, existing batches — is its own dedicated tab, built in
-- a later phase). Warehouse-wise stock itself needs no new schema: it
-- already lives in stock_bins.
--
-- Safe to re-run: every ADD COLUMN is idempotent.

SET NAMES utf8mb4;

ALTER TABLE products ADD COLUMN IF NOT EXISTS stock_item_type ENUM('Regular Stock Item','Non-Stock Item','Fixed Asset Item') NOT NULL DEFAULT 'Regular Stock Item' AFTER is_stock_item;
ALTER TABLE products ADD COLUMN IF NOT EXISTS opening_stock_date DATE DEFAULT NULL AFTER stock_item_type;
ALTER TABLE products ADD COLUMN IF NOT EXISTS valuation_rate DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER opening_stock_date;

ALTER TABLE products ADD COLUMN IF NOT EXISTS default_bin VARCHAR(30) DEFAULT NULL AFTER default_warehouse_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS issue_method ENUM('FIFO','LIFO','Moving Average') NOT NULL DEFAULT 'FIFO' AFTER default_bin;
ALTER TABLE products ADD COLUMN IF NOT EXISTS receipt_method ENUM('FIFO','LIFO','Moving Average') NOT NULL DEFAULT 'FIFO' AFTER issue_method;
ALTER TABLE products ADD COLUMN IF NOT EXISTS allow_negative_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER receipt_method;
ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_create_batch_serial TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_negative_stock;

ALTER TABLE products ADD COLUMN IF NOT EXISTS reorder_qty INT NOT NULL DEFAULT 0 AFTER reorder_level;
ALTER TABLE products ADD COLUMN IF NOT EXISTS safety_stock INT NOT NULL DEFAULT 0 AFTER reorder_qty;
ALTER TABLE products ADD COLUMN IF NOT EXISTS enable_reorder_notifications TINYINT(1) NOT NULL DEFAULT 1 AFTER safety_stock;
ALTER TABLE products ADD COLUMN IF NOT EXISTS consider_in_mrp TINYINT(1) NOT NULL DEFAULT 1 AFTER enable_reorder_notifications;

ALTER TABLE products ADD COLUMN IF NOT EXISTS has_batch_no TINYINT(1) NOT NULL DEFAULT 0 AFTER consider_in_mrp;
ALTER TABLE products ADD COLUMN IF NOT EXISTS has_serial_no TINYINT(1) NOT NULL DEFAULT 0 AFTER has_batch_no;
ALTER TABLE products ADD COLUMN IF NOT EXISTS batch_expiry_required TINYINT(1) NOT NULL DEFAULT 0 AFTER has_serial_no;
ALTER TABLE products ADD COLUMN IF NOT EXISTS batch_number_series VARCHAR(40) DEFAULT NULL AFTER batch_expiry_required;

ALTER TABLE products ADD COLUMN IF NOT EXISTS storage_section VARCHAR(60) DEFAULT NULL AFTER batch_number_series;
ALTER TABLE products ADD COLUMN IF NOT EXISTS storage_rack VARCHAR(30) DEFAULT NULL AFTER storage_section;
ALTER TABLE products ADD COLUMN IF NOT EXISTS storage_shelf VARCHAR(30) DEFAULT NULL AFTER storage_rack;
ALTER TABLE products ADD COLUMN IF NOT EXISTS storage_bin VARCHAR(30) DEFAULT NULL AFTER storage_shelf;

ALTER TABLE products ADD COLUMN IF NOT EXISTS track_stock_ageing TINYINT(1) NOT NULL DEFAULT 0 AFTER storage_bin;
ALTER TABLE products ADD COLUMN IF NOT EXISTS include_in_stock_report TINYINT(1) NOT NULL DEFAULT 1 AFTER track_stock_ageing;
ALTER TABLE products ADD COLUMN IF NOT EXISTS allow_stock_transfer TINYINT(1) NOT NULL DEFAULT 1 AFTER include_in_stock_report;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_kit_or_set TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_stock_transfer;
ALTER TABLE products ADD COLUMN IF NOT EXISTS use_alternative_item TINYINT(1) NOT NULL DEFAULT 0 AFTER is_kit_or_set;
ALTER TABLE products ADD COLUMN IF NOT EXISTS restrict_warehouse TINYINT(1) NOT NULL DEFAULT 0 AFTER use_alternative_item;
ALTER TABLE products ADD COLUMN IF NOT EXISTS block_for_stock_transactions TINYINT(1) NOT NULL DEFAULT 0 AFTER restrict_warehouse;
ALTER TABLE products ADD COLUMN IF NOT EXISTS exclude_from_inventory_valuation TINYINT(1) NOT NULL DEFAULT 0 AFTER block_for_stock_transactions;
