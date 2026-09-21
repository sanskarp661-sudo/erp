-- Migration 011: POS payment gateway integration (Cashfree).
--
-- Tracks a POS sale paid via an online payment gateway (Cashfree Payment
-- Links, currently) from the moment the QR/payment link is generated
-- until it's confirmed paid or fails. The underlying Sales Order /
-- Delivery / Invoice / stock movement are only created once the gateway
-- confirms payment via webhook (pos/cashfree_webhook.php) - never at QR
-- generation time - so an abandoned or failed payment never creates a
-- phantom sale or holds stock hostage.
--
-- Safe to re-run: CREATE TABLE is idempotent (IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pos_gateway_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(60) NOT NULL UNIQUE,
  gateway VARCHAR(20) NOT NULL DEFAULT 'cashfree',
  gateway_link_id VARCHAR(60) DEFAULT NULL,
  link_url VARCHAR(500) DEFAULT NULL,
  customer_id INT UNSIGNED NOT NULL,
  cart_json TEXT NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  status ENUM('created','paid','failed','expired') NOT NULL DEFAULT 'created',
  sales_order_id INT UNSIGNED DEFAULT NULL,
  last_webhook_payload TEXT DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A Cashfree-confirmed POS payment is recorded as a normal payments row
-- (method='upi') so it shows up in the invoice's Payment History exactly
-- like a cash/card payment - this just widens the existing ENUM.
ALTER TABLE payments MODIFY COLUMN method ENUM('cash','bank_transfer','card','cheque','other','credit_note','upi') NOT NULL DEFAULT 'cash';
