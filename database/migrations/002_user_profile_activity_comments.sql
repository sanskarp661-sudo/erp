-- Migration 002: rich User profile fields + generic activity log + comments
-- Safe to run on an already-deployed database (idempotent: uses IF NOT
-- EXISTS / ADD COLUMN IF NOT EXISTS, both supported on MariaDB 10.0.2+ and
-- Hostinger's default MariaDB version). Run this once via phpMyAdmin
-- against your live database, then re-upload the updated application files.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Extend users with a richer profile (User Details / More Information /
-- Settings tabs on the new User page).
-- ---------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS first_name VARCHAR(80) NOT NULL DEFAULT '' AFTER name,
  ADD COLUMN IF NOT EXISTS middle_name VARCHAR(80) DEFAULT NULL AFTER first_name,
  ADD COLUMN IF NOT EXISTS last_name VARCHAR(80) DEFAULT NULL AFTER middle_name,
  ADD COLUMN IF NOT EXISTS username VARCHAR(60) DEFAULT NULL AFTER last_name,
  ADD COLUMN IF NOT EXISTS language VARCHAR(20) NOT NULL DEFAULT 'en' AFTER username,
  ADD COLUMN IF NOT EXISTS time_zone VARCHAR(60) NOT NULL DEFAULT 'UTC' AFTER language,
  ADD COLUMN IF NOT EXISTS mobile_no VARCHAR(40) DEFAULT NULL AFTER time_zone,
  ADD COLUMN IF NOT EXISTS phone VARCHAR(40) DEFAULT NULL AFTER mobile_no,
  ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS bio VARCHAR(500) DEFAULT NULL AFTER address,
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER bio,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL AFTER created_at;

-- Backfill first_name for any existing rows so the "required" field on the
-- new form isn't blank for users created before this migration.
UPDATE users SET first_name = name WHERE first_name = '' OR first_name IS NULL;

-- username must be unique but nullable (MySQL/MariaDB unique indexes allow
-- multiple NULLs, so this is safe even before every user has one set).
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uniq_username'
);
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE users ADD UNIQUE KEY uniq_username (username)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Generic activity log (used by the User page's Activity feed; the
-- entity_type/entity_id shape lets it be reused for other records later).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(30) NOT NULL,
  field_name VARCHAR(80) DEFAULT NULL,
  old_value VARCHAR(255) DEFAULT NULL,
  new_value VARCHAR(255) DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity_type, entity_id),
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Generic comments (Comments box on the User page).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED DEFAULT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity_type, entity_id),
  FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed a "created this" activity entry for any user that doesn't have one
-- yet, so the Activity tab isn't empty for accounts created before this
-- migration.
INSERT INTO activity_log (entity_type, entity_id, actor_id, action, description, created_at)
SELECT 'user', u.id, u.id, 'created', 'Administrator created this', u.created_at
FROM users u
WHERE NOT EXISTS (
  SELECT 1 FROM activity_log a WHERE a.entity_type = 'user' AND a.entity_id = u.id AND a.action = 'created'
);
