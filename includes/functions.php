<?php
/**
 * General helper functions shared across all pages.
 */

/** Escape a value for safe HTML output. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    if ($path !== '' && $path[0] !== '/' && !preg_match('#^https?://#', $path)) {
        $path = '/' . $path;
    }
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : $base . $path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function money($amount): string
{
    $symbol = setting('currency_symbol', '$');
    return $symbol . number_format((float)$amount, 2);
}

function today(): string
{
    return date('Y-m-d');
}

/** Generate a sequential business code like SO-000123. */
function next_code(string $prefix, string $table, string $column): string
{
    $stmt = db()->query("SELECT $column FROM $table ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    $num = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $num = (int)$m[1] + 1;
    }
    return $prefix . '-' . str_pad((string)$num, 6, '0', STR_PAD_LEFT);
}

/** CSRF token helpers. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Invalid or expired form submission (CSRF check failed). Please go back and try again.');
    }
}

function old(string $key, $default = '')
{
    return $_SESSION['old'][$key] ?? $default;
}

function keep_old(array $data): void
{
    $_SESSION['old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function input(string $key, $default = '')
{
    $val = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($val) ? trim($val) : $val;
}

function base_url(string $path = ''): string
{
    $base = rtrim(defined('APP_URL') ? APP_URL : '', '/');
    return $base . '/' . ltrim($path, '/');
}

/**
 * Same as base_url() but appends a ?v=<file mtime> cache-buster, so
 * uploading a new assets/css/style.css or assets/js/app.js via File
 * Manager takes effect immediately instead of serving a browser-cached
 * copy of the old file.
 */
function asset_url(string $path): string
{
    $full = __DIR__ . '/../' . ltrim($path, '/');
    $version = is_file($full) ? filemtime($full) : time();
    return base_url($path) . '?v=' . $version;
}

/**
 * Generic activity log, keyed by (entity_type, entity_id). Used by the
 * User page's Activity feed; the shape is generic enough to reuse for
 * other records later.
 */
function log_activity(string $entityType, int $entityId, string $action, ?string $description = null, ?string $fieldName = null, $oldValue = null, $newValue = null): void
{
    $actorId = current_user()['id'] ?? null;
    db()->prepare('INSERT INTO activity_log (entity_type, entity_id, actor_id, action, field_name, old_value, new_value, description) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$entityType, $entityId, $actorId, $action, $fieldName, $oldValue !== null ? (string)$oldValue : null, $newValue !== null ? (string)$newValue : null, $description]);
}

/**
 * Diffs $old against $new for the given field => label map and logs one
 * 'field_changed' entry per field that actually changed, plus a single
 * 'edited' marker entry if anything changed at all. Returns the number of
 * fields that changed.
 */
function log_field_changes(string $entityType, int $entityId, array $old, array $new, array $labels): int
{
    $changed = 0;
    foreach ($labels as $field => $label) {
        $oldVal = $old[$field] ?? null;
        $newVal = $new[$field] ?? null;
        if ((string)$oldVal === (string)$newVal) {
            continue;
        }
        $changed++;
        log_activity($entityType, $entityId, 'field_changed', null, $label, $oldVal === '' ? null : $oldVal, $newVal === '' ? null : $newVal);
    }
    if ($changed > 0) {
        log_activity($entityType, $entityId, 'edited');
    }
    return $changed;
}

/** Fetches an entity's activity log, most recent first, with actor names. */
function get_activity_log(string $entityType, int $entityId): array
{
    $stmt = db()->prepare("
      SELECT a.*, u.name actor_name
      FROM activity_log a LEFT JOIN users u ON u.id = a.actor_id
      WHERE a.entity_type = ? AND a.entity_id = ?
      ORDER BY a.id DESC
    ");
    $stmt->execute([$entityType, $entityId]);
    return $stmt->fetchAll();
}

/** Renders one activity_log row as a human-readable line, ERPNext-style. */
function format_activity(array $row): string
{
    $me = current_user();
    $isYou = $me && $row['actor_id'] == $me['id'];
    $who = $isYou ? 'You' : e($row['actor_name'] ?? 'Someone');

    if (!empty($row['description'])) {
        return e($row['description']);
    }

    switch ($row['action']) {
        case 'created':
            return $who . ' created this';
        case 'edited':
            return $who . ' last edited this';
        case 'field_changed':
            $old = $row['old_value'] ?? 'null';
            $new = $row['new_value'] ?? 'null';
            return $who . ' changed the value of ' . e($row['field_name']) . ' from ' . e($old) . ' to ' . e($new);
        default:
            return $who . ' ' . e($row['action']);
    }
}

function add_comment(string $entityType, int $entityId, string $body): void
{
    db()->prepare('INSERT INTO comments (entity_type, entity_id, author_id, body) VALUES (?,?,?,?)')
        ->execute([$entityType, $entityId, current_user()['id'] ?? null, $body]);
}

function get_comments(string $entityType, int $entityId): array
{
    $stmt = db()->prepare("
      SELECT c.*, u.name author_name
      FROM comments c LEFT JOIN users u ON u.id = c.author_id
      WHERE c.entity_type = ? AND c.entity_id = ?
      ORDER BY c.id DESC
    ");
    $stmt->execute([$entityType, $entityId]);
    return $stmt->fetchAll();
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') {
            $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        }
    }
    return $initials ?: '?';
}
