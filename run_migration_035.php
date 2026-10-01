<?php
/**
 * One-time runner for database/migrations/035_custom_fields_and_api_clients.sql.
 *
 * Visit this URL once while logged in as admin to create the
 * custom_field_defs, custom_field_values, and api_clients tables. Safe
 * to load more than once (CREATE TABLE IF NOT EXISTS). Delete this file
 * once you've confirmed it ran successfully.
 */

require_once __DIR__ . '/includes/auth.php';
require_admin_edit();

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS custom_field_defs (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          entity_type VARCHAR(40) NOT NULL DEFAULT 'product',
          field_key VARCHAR(60) NOT NULL,
          label VARCHAR(120) NOT NULL,
          field_type ENUM('text','number','date','select','checkbox','textarea') NOT NULL DEFAULT 'text',
          options TEXT DEFAULT NULL,
          is_required TINYINT(1) NOT NULL DEFAULT 0,
          sort_order INT NOT NULL DEFAULT 0,
          status ENUM('active','inactive') NOT NULL DEFAULT 'active',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_custom_field_defs (entity_type, field_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "[1/3] custom_field_defs table ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS custom_field_values (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          entity_type VARCHAR(40) NOT NULL,
          entity_id INT UNSIGNED NOT NULL,
          field_key VARCHAR(60) NOT NULL,
          value TEXT,
          UNIQUE KEY uq_custom_field_values (entity_type, entity_id, field_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "[2/3] custom_field_values table ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS api_clients (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(100) NOT NULL,
          api_key VARCHAR(64) NOT NULL UNIQUE,
          status ENUM('active','inactive') NOT NULL DEFAULT 'active',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_used_at DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "[3/3] api_clients table ready.\n";

    echo "\nMigration 035 complete. You can delete run_migration_035.php now.\n";
} catch (PDOException $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
}
