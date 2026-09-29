-- Migration 025: Purchase Order redesign (all six tabs).
--
-- Brings the Purchase Order form up to the Sales Order's tabbed layout:
-- Details, Items, Taxes & Charges, Shipping & Delivery, Payment Terms and
-- More Info. Adds the header fields for each tab on purchase_orders,
-- per-line description / warehouse / rate / discount / required-by on
-- purchase_order_items, and two child tables (purchase_order_taxes,
-- purchase_order_payment_schedule) that mirror their sales-side twins.
--
-- purchase_order_items.unit_cost keeps meaning "net cost per unit" (rate
-- after line discount), so Goods Receipts, Purchase Returns and Purchase
-- Invoices that copy it from a PO keep working unchanged. The list rate
-- before discount is stored separately in the new `rate` column.
--
-- Also seeds Input CGST / SGST / IGST tax accounts (purchase-side GST)
-- and a "Standard Buying" price list.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN / INSERT is idempotent.

SET NAMES utf8mb4;

-- Details tab
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS vendor_contact VARCHAR(120) DEFAULT NULL AFTER vendor_id;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS vendor_address VARCHAR(255) DEFAULT NULL AFTER vendor_contact;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS required_by DATE DEFAULT NULL AFTER order_date;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS purchase_type VARCHAR(60) DEFAULT NULL AFTER required_by;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS buyer_id INT UNSIGNED DEFAULT NULL AFTER purchase_type;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS price_list_id INT UNSIGNED DEFAULT NULL AFTER buyer_id;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS currency VARCHAR(3) NOT NULL DEFAULT 'INR' AFTER price_list_id;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS vendor_gstin VARCHAR(20) DEFAULT NULL AFTER currency;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS vendor_quote_no VARCHAR(60) DEFAULT NULL AFTER vendor_gstin;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS material_request_no VARCHAR(60) DEFAULT NULL AFTER vendor_quote_no;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS project VARCHAR(120) DEFAULT NULL AFTER material_request_no;

-- Taxes & Charges tab
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS net_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total_amount;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS tax_template_id INT UNSIGNED DEFAULT NULL AFTER net_amount;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS place_of_supply VARCHAR(120) DEFAULT NULL AFTER tax_template_id;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS gst_category ENUM('registered_business','unregistered_business','composition','overseas','sez') DEFAULT NULL AFTER place_of_supply;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS reverse_charge TINYINT(1) NOT NULL DEFAULT 0 AFTER gst_category;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS tax_remarks VARCHAR(255) DEFAULT NULL AFTER reverse_charge;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS rounding_method ENUM('nearest','up','down') NOT NULL DEFAULT 'nearest' AFTER tax_remarks;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS rounding_precision DECIMAL(10,2) NOT NULL DEFAULT 0.01 AFTER rounding_method;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS additional_discount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER rounding_precision;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS additional_charge DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER additional_discount;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS adjustment_type ENUM('none','add','subtract') NOT NULL DEFAULT 'none' AFTER additional_charge;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS adjustment_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER adjustment_type;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS adjustment_remarks VARCHAR(255) DEFAULT NULL AFTER adjustment_amount;

-- Shipping & Delivery tab
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS ship_to_warehouse_id INT UNSIGNED DEFAULT NULL AFTER adjustment_remarks;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS expected_delivery_date DATE DEFAULT NULL AFTER ship_to_warehouse_id;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS expected_dispatch_date DATE DEFAULT NULL AFTER expected_delivery_date;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER expected_dispatch_date;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_terms VARCHAR(60) DEFAULT NULL AFTER delivery_priority;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS mode_of_transport VARCHAR(30) DEFAULT NULL AFTER delivery_terms;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS shipping_partner_id INT UNSIGNED DEFAULT NULL AFTER mode_of_transport;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS freight_terms VARCHAR(60) DEFAULT NULL AFTER shipping_partner_id;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS tracking_no VARCHAR(80) DEFAULT NULL AFTER freight_terms;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_remarks VARCHAR(255) DEFAULT NULL AFTER tracking_no;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS allow_partial_receipt TINYINT(1) NOT NULL DEFAULT 1 AFTER delivery_remarks;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS inspection_required TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_partial_receipt;

-- Payment Terms tab
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS payment_terms_template_id INT UNSIGNED DEFAULT NULL AFTER inspection_required;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS payment_terms VARCHAR(120) DEFAULT NULL AFTER payment_terms_template_id;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS payment_method VARCHAR(60) DEFAULT NULL AFTER payment_terms;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS payment_due_date_basis ENUM('against_receipt','against_order_date','against_invoice') NOT NULL DEFAULT 'against_invoice' AFTER payment_method;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS advance_percentage DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER payment_due_date_basis;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS vendor_bank_details VARCHAR(255) DEFAULT NULL AFTER advance_percentage;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS payment_instructions TEXT DEFAULT NULL AFTER vendor_bank_details;

-- More Info tab
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS order_type VARCHAR(40) NOT NULL DEFAULT 'Standard' AFTER payment_instructions;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS cost_center VARCHAR(120) DEFAULT NULL AFTER order_type;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS business_unit VARCHAR(120) DEFAULT NULL AFTER cost_center;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS approver_id INT UNSIGNED DEFAULT NULL AFTER business_unit;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS terms_conditions TEXT DEFAULT NULL AFTER approver_id;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS remarks_internal TEXT DEFAULT NULL AFTER terms_conditions;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS tags VARCHAR(255) DEFAULT NULL AFTER remarks_internal;

-- Items tab
ALTER TABLE purchase_order_items ADD COLUMN IF NOT EXISTS description VARCHAR(255) DEFAULT NULL AFTER product_id;
ALTER TABLE purchase_order_items ADD COLUMN IF NOT EXISTS warehouse_id INT UNSIGNED DEFAULT NULL AFTER description;
ALTER TABLE purchase_order_items ADD COLUMN IF NOT EXISTS rate DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER uom_conversion_factor;
ALTER TABLE purchase_order_items ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER rate;
ALTER TABLE purchase_order_items ADD COLUMN IF NOT EXISTS required_by DATE DEFAULT NULL AFTER subtotal;
-- Existing lines had no discount, so their list rate equals their cost.
UPDATE purchase_order_items SET rate = unit_cost WHERE rate = 0 AND unit_cost <> 0;

CREATE TABLE IF NOT EXISTS purchase_order_taxes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id INT UNSIGNED NOT NULL,
  type ENUM('on_item','on_order') NOT NULL DEFAULT 'on_item',
  account_head_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(120) DEFAULT NULL,
  based_on ENUM('net_amount','actual_amount') NOT NULL DEFAULT 'net_amount',
  rate_or_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (account_head_id) REFERENCES ledger_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_payment_schedule (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id INT UNSIGNED NOT NULL,
  due_on ENUM('order_date','on_receipt','on_invoice','fixed_days') NOT NULL DEFAULT 'order_date',
  days_from INT NOT NULL DEFAULT 0,
  payment_type ENUM('advance','part_payment','balance') NOT NULL DEFAULT 'balance',
  percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(120) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ledger_accounts (name, account_type)
SELECT * FROM (SELECT x.name, x.account_type FROM (
  SELECT 'Input CGST' name, 'tax' account_type UNION ALL
  SELECT 'Input SGST', 'tax' UNION ALL
  SELECT 'Input IGST', 'tax' UNION ALL
  SELECT 'Freight Inward', 'other'
) x) t
WHERE NOT EXISTS (SELECT 1 FROM ledger_accounts la WHERE la.name = t.name);

INSERT INTO price_lists (name, currency, is_default)
SELECT 'Standard Buying', 'INR', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM price_lists WHERE name = 'Standard Buying');
