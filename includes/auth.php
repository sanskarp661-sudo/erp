<?php
/**
 * Bootstrap: session, DB, helpers, and auth guards.
 * Include this at the very top of every page (before any HTML output).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('/login.php');
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $exempt = str_contains($path, '/users/change_password.php') || str_contains($path, '/logout.php');
    if (!$exempt && !empty(current_user()['must_change_password'])) {
        redirect('/users/change_password.php');
    }
}

/** @param string[] $roles Allowed roles, e.g. ['admin','manager'] */
function require_role(array $roles): void
{
    require_login();
    $user = current_user();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        require __DIR__ . '/header.php';
        echo '<div class="alert alert-danger">You do not have permission to view this page.</div>';
        require __DIR__ . '/footer.php';
        exit;
    }
}

function is_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

function attempt_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'                   => $user['id'],
        'name'                 => $user['name'],
        'email'                => $user['email'],
        'role'                 => $user['role'],
        'must_change_password' => (bool)$user['must_change_password'],
    ];

    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
