-- Migration 010: Units of Measure (UOM) master.
--
-- Products previously had a free-text "Unit" field (whatever a user
-- happened to type — "pcs", "PCS", "Pcs", "pieces"...). This adds a
-- proper UOM master (maintained under Inventory -> Units of Measure,
-- same pattern as Categories) and switches the product form's Unit
-- field to a dropdown sourced from it. products.unit itself is
-- unchanged (still the plain name string) so every existing query/page
-- that reads it keeps working untouched — only how it's entered changes.
--
-- Backfills the UOM master from whatever distinct unit values already
-- exist on your products, so nothing on an existing product looks
-- "missing" after this runs.
--
-- Safe to re-run: CREATE TABLE is idempotent (IF NOT EXISTS) and the
-- backfill INSERT...SELECT only adds names not already present.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS uom (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(30) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO uom (name)
SELECT DISTINCT p.unit FROM products p
WHERE p.unit IS NOT NULL AND p.unit <> ''
  AND NOT EXISTS (SELECT 1 FROM uom u WHERE u.name = p.unit);

INSERT INTO uom (name)
SELECT * FROM (SELECT 'pcs' AS name) t
WHERE NOT EXISTS (SELECT 1 FROM uom u WHERE u.name = 'pcs');
