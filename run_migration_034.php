<?php
/**
 * One-time runner for database/migrations/034_user_image.sql.
 *
 * Visit this URL once while logged in as admin to add the users.image
 * column. Safe to load more than once (ADD COLUMN IF NOT EXISTS). Delete
 * this file once you've confirmed it ran successfully.
 */

require_once __DIR__ . '/includes/auth.php';
require_admin_edit();

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = db();
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT NULL AFTER bio");
    echo "Migration 034 complete: users.image column is ready.\n";
    echo "\nYou can delete run_migration_034.php now.\n";
} catch (PDOException $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
}
