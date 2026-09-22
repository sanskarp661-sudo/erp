-- Migration 022: Item Master redesign, Phase 5 (Sales + Purchase tabs).
--
-- Purchase-side: default supplier/purchase price list, order qty rules,
-- packaging, additional purchase options (flags only — no real
-- subcontracting/drop-ship/supplier-portal workflow exists in this app,
-- same "stored but not automated" pattern as the Sales Order's DN/stock
-- checkboxes), quality inspection fields (flags only, no QC workflow),
-- and product_suppliers (preferred supplier list).
--
-- Sales-side: item customer group, order qty rules, sales
-- description/marketing info, item availability flags, sales
-- forecasting fields, and product_customer_rules (per-customer
-- qty/discount rules — distinct from Pricing tab's product_customer_prices,
-- which is about rate/date-range, not quantity bands).
--
-- default_package_type/items_per_package are edited once, from the
-- Purchase tab only (packaging is fundamentally a receiving/shipping
-- unit concept) — the Sales tab's own "Sales UOM & Packaging" card only
-- shows the already-existing sales_uom/sales_uom_conversion_factor
-- fields, so no field has two editable inputs sharing a name in the
-- same <form>.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

SET NAMES utf8mb4;

ALTER TABLE products ADD COLUMN IF NOT EXISTS default_supplier_id INT UNSIGNED DEFAULT NULL AFTER tax_notes;
ALTER TABLE products ADD COLUMN IF NOT EXISTS default_purchase_price_list_id INT UNSIGNED DEFAULT NULL AFTER default_supplier_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS purchase_min_order_qty INT NOT NULL DEFAULT 0 AFTER default_purchase_price_list_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS purchase_max_order_qty INT NOT NULL DEFAULT 0 AFTER purchase_min_order_qty;
ALTER TABLE products ADD COLUMN IF NOT EXISTS purchase_order_qty_increment INT NOT NULL DEFAULT 1 AFTER purchase_max_order_qty;
ALTER TABLE products ADD COLUMN IF NOT EXISTS receipt_tolerance_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER purchase_order_qty_increment;
ALTER TABLE products ADD COLUMN IF NOT EXISTS over_delivery_allowance_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER receipt_tolerance_percent;
ALTER TABLE products ADD COLUMN IF NOT EXISTS default_package_type VARCHAR(60) DEFAULT NULL AFTER over_delivery_allowance_percent;
ALTER TABLE products ADD COLUMN IF NOT EXISTS items_per_package INT NOT NULL DEFAULT 1 AFTER default_package_type;
ALTER TABLE products ADD COLUMN IF NOT EXISTS purchase_description VARCHAR(500) DEFAULT NULL AFTER items_per_package;
ALTER TABLE products ADD COLUMN IF NOT EXISTS requires_purchase_order TINYINT(1) NOT NULL DEFAULT 1 AFTER purchase_description;
ALTER TABLE products ADD COLUMN IF NOT EXISTS allow_receipt_without_po TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_purchase_order;
ALTER TABLE products ADD COLUMN IF NOT EXISTS track_supplier_batch_serial TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_receipt_without_po;
ALTER TABLE products ADD COLUMN IF NOT EXISTS include_in_supplier_portal TINYINT(1) NOT NULL DEFAULT 0 AFTER track_supplier_batch_serial;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_drop_ship_item TINYINT(1) NOT NULL DEFAULT 0 AFTER include_in_supplier_portal;
ALTER TABLE products ADD COLUMN IF NOT EXISTS allow_subcontracting TINYINT(1) NOT NULL DEFAULT 0 AFTER is_drop_ship_item;
ALTER TABLE products ADD COLUMN IF NOT EXISTS maintain_last_purchase_rate TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_subcontracting;
ALTER TABLE products ADD COLUMN IF NOT EXISTS inspection_required ENUM('Yes','No') NOT NULL DEFAULT 'No' AFTER maintain_last_purchase_rate;
ALTER TABLE products ADD COLUMN IF NOT EXISTS sampling_rate_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER inspection_required;
ALTER TABLE products ADD COLUMN IF NOT EXISTS quality_rating_default VARCHAR(30) DEFAULT NULL AFTER sampling_rate_percent;
ALTER TABLE products ADD COLUMN IF NOT EXISTS reject_if_quality_check_fails TINYINT(1) NOT NULL DEFAULT 0 AFTER quality_rating_default;

ALTER TABLE products ADD COLUMN IF NOT EXISTS item_customer_group VARCHAR(120) DEFAULT NULL AFTER reject_if_quality_check_fails;
ALTER TABLE products ADD COLUMN IF NOT EXISTS sales_min_order_qty INT NOT NULL DEFAULT 0 AFTER item_customer_group;
ALTER TABLE products ADD COLUMN IF NOT EXISTS sales_max_order_qty INT NOT NULL DEFAULT 0 AFTER sales_min_order_qty;
ALTER TABLE products ADD COLUMN IF NOT EXISTS sales_order_qty_increment INT NOT NULL DEFAULT 1 AFTER sales_max_order_qty;
ALTER TABLE products ADD COLUMN IF NOT EXISTS sales_lead_time_days INT NOT NULL DEFAULT 0 AFTER sales_order_qty_increment;
ALTER TABLE products ADD COLUMN IF NOT EXISTS delivery_time_days INT NOT NULL DEFAULT 0 AFTER sales_lead_time_days;
ALTER TABLE products ADD COLUMN IF NOT EXISTS weight_for_shipping_kg DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER delivery_time_days;
ALTER TABLE products ADD COLUMN IF NOT EXISTS sales_description VARCHAR(500) DEFAULT NULL AFTER weight_for_shipping_kg;
ALTER TABLE products ADD COLUMN IF NOT EXISTS marketing_material VARCHAR(60) DEFAULT NULL AFTER sales_description;
ALTER TABLE products ADD COLUMN IF NOT EXISTS item_website VARCHAR(255) DEFAULT NULL AFTER marketing_material;
ALTER TABLE products ADD COLUMN IF NOT EXISTS available_for_online_sales TINYINT(1) NOT NULL DEFAULT 1 AFTER item_website;
ALTER TABLE products ADD COLUMN IF NOT EXISTS available_for_retail_sales TINYINT(1) NOT NULL DEFAULT 1 AFTER available_for_online_sales;
ALTER TABLE products ADD COLUMN IF NOT EXISTS available_for_b2b_sales TINYINT(1) NOT NULL DEFAULT 1 AFTER available_for_retail_sales;
ALTER TABLE products ADD COLUMN IF NOT EXISTS not_discountable TINYINT(1) NOT NULL DEFAULT 0 AFTER available_for_b2b_sales;
ALTER TABLE products ADD COLUMN IF NOT EXISTS requires_approval_for_discount TINYINT(1) NOT NULL DEFAULT 0 AFTER not_discountable;
ALTER TABLE products ADD COLUMN IF NOT EXISTS show_in_customer_portal TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_approval_for_discount;
ALTER TABLE products ADD COLUMN IF NOT EXISTS default_monthly_sales_qty INT NOT NULL DEFAULT 0 AFTER show_in_customer_portal;
ALTER TABLE products ADD COLUMN IF NOT EXISTS seasonal_demand ENUM('Low','Normal','High') NOT NULL DEFAULT 'Normal' AFTER default_monthly_sales_qty;
ALTER TABLE products ADD COLUMN IF NOT EXISTS preferred_sales_warehouse_id INT UNSIGNED DEFAULT NULL AFTER seasonal_demand;

CREATE TABLE IF NOT EXISTS product_suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  supplier_part_no VARCHAR(60) DEFAULT NULL,
  lead_time_days INT NOT NULL DEFAULT 0,
  last_purchase_rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_preferred TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (supplier_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_customer_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  customer_group VARCHAR(120) DEFAULT NULL,
  price_list_id INT UNSIGNED DEFAULT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  min_qty INT NOT NULL DEFAULT 0,
  max_qty INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
