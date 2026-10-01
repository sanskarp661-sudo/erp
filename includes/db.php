<?php
/**
 * Shared PDO connection. Included by every page via includes/auth.php.
 */

if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config/config.php';
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
            }
            die('Database connection failed. Please check config/config.php.');
        }
        db_ensure_runtime_tables($pdo);
    }

    return $pdo;
}

/**
 * Creates tables that newer code depends on if their one-time migration
 * runner hasn't been visited yet, so a deploy can never take pages or the
 * website API down while waiting for a manual step. Uses exactly the
 * CREATE TABLE IF NOT EXISTS statements of the migration files, runs at most
 * once per server (marker file in the temp dir), and never throws.
 *
 * Covered: 035_custom_fields_and_api_clients.sql.
 */
function db_ensure_runtime_tables(PDO $pdo): void
{
    $marker = sys_get_temp_dir() . '/erp_runtime_tables_035_' . md5(DB_HOST . '|' . DB_NAME);
    if (is_file($marker)) {
        return;
    }
    try {
        $existing = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('custom_field_defs', 'custom_field_values', 'api_clients')"
        )->fetchColumn();
        if ((int)$existing < 3) {
            foreach ([
        <<<'SQL'
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
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS custom_field_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  field_key VARCHAR(60) NOT NULL,
  value TEXT,
  UNIQUE KEY uq_custom_field_values (entity_type, entity_id, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS api_clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  api_key VARCHAR(64) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
            ] as $sql) {
                $pdo->exec($sql);
            }
        }
        @file_put_contents($marker, (string)time());
    } catch (Throwable $e) {
        error_log('[erp] could not create migration 035 tables automatically: ' . $e->getMessage());
    }
}
