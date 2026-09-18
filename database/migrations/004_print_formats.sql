-- Migration 004: custom Print Format templates, switchable per document.
-- Safe to run on an already-deployed database (idempotent). Run this once
-- via phpMyAdmin against your live database, then re-upload the
-- application files.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS print_formats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  doctype VARCHAR(40) NOT NULL,
  html_template LONGTEXT NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_doctype (doctype),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
