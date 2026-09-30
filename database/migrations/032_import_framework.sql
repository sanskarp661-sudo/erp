-- Migration 032: Generic data import framework.
--
-- One batch per uploaded file; one row per spreadsheet row, so every
-- import can be audited later (who imported what, which rows failed and
-- why, which record each successful row created/updated). Nothing here is
-- entity-specific — each importer (registered in includes/importers.php)
-- just writes into whatever tables it owns after validating a row; this
-- migration only adds the bookkeeping tables shared by all of them.
--
-- Safe to re-run: every CREATE TABLE is idempotent.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS import_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(60) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  status ENUM('pending','validated','processing','completed','failed') NOT NULL DEFAULT 'pending',
  total_rows INT NOT NULL DEFAULT 0,
  success_count INT NOT NULL DEFAULT 0,
  error_count INT NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_batch_rows (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_batch_id INT UNSIGNED NOT NULL,
  row_num INT NOT NULL,
  status ENUM('pending','success','error','skipped') NOT NULL DEFAULT 'pending',
  message VARCHAR(500) DEFAULT NULL,
  raw_data TEXT DEFAULT NULL,
  created_record_id INT UNSIGNED DEFAULT NULL,
  FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
