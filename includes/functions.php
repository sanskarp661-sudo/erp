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
