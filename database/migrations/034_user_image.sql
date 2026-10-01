-- Migration 034: profile photo for users.
--
-- Safe to re-run (ADD COLUMN IF NOT EXISTS, supported on MariaDB 10.0.2+
-- and Hostinger's default MariaDB version).

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT NULL AFTER bio;
