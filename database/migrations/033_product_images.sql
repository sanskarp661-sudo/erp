-- Migration 033: multiple item images (up to 10 per product).
--
-- products.image is untouched and keeps working exactly as before for
-- every existing reader (products list thumbnail, print engine image
-- token, website sync API's image_url) — it now mirrors whichever row
-- in product_images is marked is_default, kept in sync by
-- inventory/product_form.php on every save.
--
-- Safe to re-run: the CREATE TABLE is idempotent, and the backfill only
-- inserts a product_images row for a product that doesn't have one yet.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS product_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  image VARCHAR(255) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: every existing product that already has a single photo gets
-- a matching product_images row (marked default), so it shows up in the
-- new gallery immediately instead of looking like it lost its photo.
INSERT INTO product_images (product_id, image, is_default, sort_order)
SELECT p.id, p.image, 1, 0
FROM products p
WHERE p.image IS NOT NULL AND p.image <> ''
  AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.id);
