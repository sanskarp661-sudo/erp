-- Migration 017: Sales Order redesign, Phase 6 (Documents tab).
--
-- Adds a generic attachments table (entity_type/entity_id, mirroring the
-- existing comments/activity_log tables) so any document can carry file
-- attachments, starting with the Sales Order's Documents section. The
-- Linked Documents and Comments/Activity parts of that section reuse
-- infrastructure that already exists (foreign-key relations already on
-- sales_orders, and the comments/activity_log tables already used by
-- users/user_form.php) — no schema changes needed for those.
--
-- Safe to re-run: CREATE TABLE is idempotent.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity_type, entity_id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
