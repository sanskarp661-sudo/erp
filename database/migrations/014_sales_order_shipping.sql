-- Migration 014: Sales Order redesign, Phase 3 (Shipping & Delivery tab).
--
-- Adds a Shipping Partners master and a Shipping & Delivery tab's worth
-- of fields on sales_orders: delivery scheduling, ship-to address,
-- courier/tracking details, and the "Additional Options" checkboxes from
-- the mockup. Those checkboxes are stored as preferences only in this
-- phase — no automatic Delivery Note creation or stock update is wired
-- up yet (that would mean auto-confirming a pending order, a bigger
-- workflow change than this tab's own scope), so they're informational
-- until a later phase wires the automation.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shipping_partners (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS promised_delivery_date DATE DEFAULT NULL AFTER required_delivery_date;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS delivery_priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER promised_delivery_date;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS fulfillment_type ENUM('complete_order','partial_allowed') NOT NULL DEFAULT 'complete_order' AFTER delivery_priority;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS delivery_terms VARCHAR(60) DEFAULT NULL AFTER fulfillment_type;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS shipping_rule VARCHAR(120) DEFAULT NULL AFTER delivery_terms;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS delivery_remarks VARCHAR(255) DEFAULT NULL AFTER shipping_rule;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS ship_to_address_id INT UNSIGNED DEFAULT NULL AFTER delivery_remarks;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS shipping_partner_id INT UNSIGNED DEFAULT NULL AFTER ship_to_address_id;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS shipping_service_type VARCHAR(60) DEFAULT NULL AFTER shipping_partner_id;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS shipping_method VARCHAR(60) DEFAULT NULL AFTER shipping_service_type;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS tracking_no VARCHAR(120) DEFAULT NULL AFTER shipping_method;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS expected_dispatch_date DATE DEFAULT NULL AFTER tracking_no;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS expected_delivery_date DATE DEFAULT NULL AFTER expected_dispatch_date;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS create_dn_after_submit TINYINT(1) NOT NULL DEFAULT 1 AFTER expected_delivery_date;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS update_stock_on_submit TINYINT(1) NOT NULL DEFAULT 1 AFTER create_dn_after_submit;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS allow_partial_delivery TINYINT(1) NOT NULL DEFAULT 0 AFTER update_stock_on_submit;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS notify_customer TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_partial_delivery;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS print_picking_list TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_customer;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS print_shipping_label TINYINT(1) NOT NULL DEFAULT 0 AFTER print_picking_list;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS include_shipping_in_total TINYINT(1) NOT NULL DEFAULT 1 AFTER print_shipping_label;
