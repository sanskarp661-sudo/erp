-- Migration 009: separate POS from Sales.
--
-- Adds sales_orders.channel ('online' vs 'pos') so a POS-made sale can be
-- told apart from a regular counter/manual Sales Order precisely, instead
-- of the old fragile signal of matching notes = 'POS sale'. The POS
-- module now has its own order history (pos/orders.php, channel='pos'
-- only) completely separate from the Sales module's own Sales Orders
-- list — a POS sale still creates a real Sales Order + Invoice + Payment
-- underneath, so it still shows up everywhere in Sales/Finance exactly
-- as before, it just no longer shares a *list page* with manual orders.
--
-- Backfills channel='pos' onto every historical order that was created
-- by the old POS code (identifiable by its notes field, which the POS
-- checkout flow always set to exactly 'POS sale').
--
-- Safe to re-run: the ADD COLUMN is idempotent (IF NOT EXISTS), and the
-- backfill UPDATE only touches rows still at the 'online' default, so a
-- second run is a no-op.

SET NAMES utf8mb4;

ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS channel ENUM('online','pos') NOT NULL DEFAULT 'online' AFTER status;

UPDATE sales_orders SET channel = 'pos' WHERE notes = 'POS sale' AND channel <> 'pos';
