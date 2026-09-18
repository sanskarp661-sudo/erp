-- Replaces the single users.role column with a many-to-many role
-- assignment, so a user can hold several roles at once (System Admin,
-- Admin, System Viewer, or a module-scoped role like Purchase Manager).
-- Safe to re-run: CREATE TABLE IF NOT EXISTS + INSERT IGNORE with a
-- unique key. The old users.role column is left in place (unused by the
-- app from this point on) rather than dropped, to keep this migration
-- non-destructive.

CREATE TABLE IF NOT EXISTS user_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  role_key VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_role (user_id, role_key),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One-time migration of each existing user's old single role into the new
-- model: old 'admin' becomes System Admin (full access, same as before);
-- old 'manager'/'staff' become System Viewer (safe, view-only default —
-- reassign real module roles for these users afterward from Users).
INSERT IGNORE INTO user_roles (user_id, role_key)
SELECT id, CASE role WHEN 'admin' THEN 'system_admin' ELSE 'system_viewer' END
FROM users;
