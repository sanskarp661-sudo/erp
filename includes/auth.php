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
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/stock.php';

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

    // A session created before the multi-role permission system shipped
    // won't have 'roles' set yet — force a clean re-login so it's rebuilt
    // from the database instead of silently running with no permissions.
    // (unset(), not logout()/session_destroy(), so this flash message
    // survives on the still-active session into the next request.)
    if (!isset($_SESSION['user']['roles'])) {
        unset($_SESSION['user']);
        flash('info', 'Please log in again — your session needs to be refreshed for the updated permissions system.');
        redirect('/login.php');
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $exempt = str_contains($path, '/users/change_password.php') || str_contains($path, '/logout.php');
    if (!$exempt && !empty(current_user()['must_change_password'])) {
        redirect('/users/change_password.php');
    }
}

function attempt_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    try {
        $rolesStmt = db()->prepare('SELECT role_key FROM user_roles WHERE user_id = ?');
        $rolesStmt->execute([$user['id']]);
        $roles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        http_response_code(500);
        die('The "user_roles" table is missing. Please run database/migrations/005_roles_permissions.sql via phpMyAdmin, then try logging in again.');
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'                   => $user['id'],
        'name'                 => $user['name'],
        'email'                => $user['email'],
        'roles'                => $roles,
        'must_change_password' => (bool)$user['must_change_password'],
    ];

    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
