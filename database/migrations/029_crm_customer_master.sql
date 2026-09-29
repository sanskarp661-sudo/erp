-- Migration 029: Customer master redesign (CRM, six tabs).
--
-- Brings the CRM Customer form up to the tabbed layout used by the Sales
-- Order, Purchase Order and Item Master: Details, Contact & Address,
-- Tax & Compliance, Sales & Pricing, Credit & Payment and More Info.
--
-- The new "default" columns (price list, tax template, payment terms,
-- sales person, ...) are what a new Sales Order pre-fills from when the
-- customer is picked. credit_hold / bypass_credit_check drive the credit
-- check the Sales Order now runs on save. customers.address stays as the
-- single free-text address that prints and invoices read; the Customer
-- form keeps it in sync with the customer's default address row.
--
-- Numbered 029 so it can't collide with other modules' work in flight
-- (026 Stock Entry, 028 HRM); it only touches the customers table and
-- does not depend on either.
--
-- Safe to re-run: every ADD COLUMN / UPDATE is idempotent.

SET NAMES utf8mb4;

-- Details tab
ALTER TABLE customers ADD COLUMN IF NOT EXISTS customer_code VARCHAR(30) DEFAULT NULL AFTER id;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS customer_type ENUM('company','individual') NOT NULL DEFAULT 'company' AFTER name;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS customer_group VARCHAR(60) DEFAULT NULL AFTER customer_type;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS territory VARCHAR(100) DEFAULT NULL AFTER company;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS industry VARCHAR(100) DEFAULT NULL AFTER territory;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS website VARCHAR(150) DEFAULT NULL AFTER industry;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active' AFTER website;

-- Contact & Address tab (addresses themselves live in customer_addresses)
ALTER TABLE customers ADD COLUMN IF NOT EXISTS contact_person VARCHAR(120) DEFAULT NULL AFTER status;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS designation VARCHAR(100) DEFAULT NULL AFTER contact_person;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS mobile VARCHAR(40) DEFAULT NULL AFTER phone;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS alt_email VARCHAR(150) DEFAULT NULL AFTER mobile;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS preferred_contact ENUM('email','phone','whatsapp','any') NOT NULL DEFAULT 'any' AFTER alt_email;

-- Tax & Compliance tab
ALTER TABLE customers ADD COLUMN IF NOT EXISTS gstin VARCHAR(15) DEFAULT NULL AFTER credit_limit;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS pan VARCHAR(10) DEFAULT NULL AFTER gstin;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS gst_category VARCHAR(30) DEFAULT NULL AFTER pan;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS place_of_supply VARCHAR(60) DEFAULT NULL AFTER gst_category;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS tax_template_id INT UNSIGNED DEFAULT NULL AFTER place_of_supply;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS tax_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER tax_template_id;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS exemption_certificate_no VARCHAR(60) DEFAULT NULL AFTER tax_exempt;

-- Sales & Pricing tab
ALTER TABLE customers ADD COLUMN IF NOT EXISTS price_list_id INT UNSIGNED DEFAULT NULL AFTER exemption_certificate_no;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS currency VARCHAR(3) DEFAULT NULL AFTER price_list_id;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS sales_person_id INT UNSIGNED DEFAULT NULL AFTER currency;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS sales_channel VARCHAR(40) DEFAULT NULL AFTER sales_person_id;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS market_segment VARCHAR(100) DEFAULT NULL AFTER sales_channel;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS region VARCHAR(100) DEFAULT NULL AFTER market_segment;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS shipping_partner_id INT UNSIGNED DEFAULT NULL AFTER region;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS delivery_terms VARCHAR(255) DEFAULT NULL AFTER shipping_partner_id;

-- Credit & Payment tab (credit_limit already exists, from migration 015)
ALTER TABLE customers ADD COLUMN IF NOT EXISTS payment_terms_template_id INT UNSIGNED DEFAULT NULL AFTER delivery_terms;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS payment_method VARCHAR(40) DEFAULT NULL AFTER payment_terms_template_id;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS credit_hold TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_method;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS bypass_credit_check TINYINT(1) NOT NULL DEFAULT 0 AFTER credit_hold;

-- More Info tab
ALTER TABLE customers ADD COLUMN IF NOT EXISTS lead_source VARCHAR(60) DEFAULT NULL AFTER bypass_credit_check;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS referred_by VARCHAR(150) DEFAULT NULL AFTER lead_source;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS campaign VARCHAR(150) DEFAULT NULL AFTER referred_by;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS customer_since DATE DEFAULT NULL AFTER campaign;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS tags VARCHAR(255) DEFAULT NULL AFTER customer_since;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL AFTER tags;

-- Give every existing customer a code (CUST-00001 ...) before the unique key.
UPDATE customers SET customer_code = CONCAT('CUST-', LPAD(id, 5, '0')) WHERE customer_code IS NULL OR customer_code = '';
ALTER TABLE customers ADD UNIQUE KEY IF NOT EXISTS uniq_customer_code (customer_code);
UPDATE customers SET customer_since = DATE(created_at) WHERE customer_since IS NULL;
