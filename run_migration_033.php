<?php
/**
 * One-time runner for database/migrations/033_product_images.sql.
 *
 * Visit this URL once while logged in as an admin to create the
 * product_images table and backfill existing single-image products into
 * it. Safe to load more than once — the underlying SQL is idempotent
 * (CREATE TABLE IF NOT EXISTS + an INSERT that only adds a row for a
 * product that doesn't already have one). Delete this file once you've
 * confirmed it ran successfully.
 */

require_once __DIR__ . '/includes/auth.php';
require_admin_edit();

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_images (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          product_id INT UNSIGNED NOT NULL,
          image VARCHAR(255) NOT NULL,
          is_default TINYINT(1) NOT NULL DEFAULT 0,
          sort_order INT NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "[1/2] product_images table ready.\n";

    $backfilled = $pdo->exec("
        INSERT INTO product_images (product_id, image, is_default, sort_order)
        SELECT p.id, p.image, 1, 0
        FROM products p
        WHERE p.image IS NOT NULL AND p.image <> ''
          AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.id)
    ");
    echo "[2/2] Backfilled $backfilled existing product photo(s) into the new gallery table.\n";

    echo "\nMigration 033 complete. You can delete run_migration_033.php now.\n";
} catch (PDOException $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
}
