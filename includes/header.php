<?php
/**
 * Shared page chrome (top of page). Requires includes/auth.php to already
 * be loaded and require_login() to have been called by the page.
 * A page may set $page_title before including this file.
 *
 * Sidebar is context-aware (see includes/modules.php):
 *  - Inside a module (path matches a module's "match" string) -> that
 *    module's own feature/doctype list.
 *  - Inside /users/ -> the Administration list.
 *  - Otherwise (e.g. the main dashboard) -> the top-level module grid.
 */
require_once __DIR__ . '/modules.php';

$user = current_user();
$current_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$currentModuleKey = current_module_key($current_path);
$inAdminSection = str_contains($current_path, $ADMIN_ITEMS['match']);

function nav_active(string $needle, string $current): string
{
    return str_contains($current, $needle) ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? e($page_title) . ' · ' : '' ?><?= e(setting('company_name', APP_NAME)) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="<?= base_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php if ($user): ?>
<div class="app-wrapper">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <i class="fa-solid fa-layer-group"></i>
      <span><?= e(setting('company_name', APP_NAME)) ?></span>
    </div>
    <nav class="sidebar-nav">
      <a href="<?= base_url('dashboard.php') ?>" class="<?= $current_path === '/dashboard.php' || $current_path === '/index.php' || $current_path === '/' ? 'active' : '' ?>"><i class="fa-solid fa-gauge"></i> Dashboard</a>

      <?php if ($currentModuleKey): ?>
        <?php $mod = $MODULES[$currentModuleKey]; ?>
        <a href="<?= base_url('dashboard.php') ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> All Modules</a>
        <div class="nav-section"><i class="<?= e($mod['icon']) ?>"></i> <?= e($mod['label']) ?></div>
        <?php foreach ($mod['items'] as $item): ?>
          <a href="<?= base_url($item['url']) ?>" class="<?= nav_active($item['match'], $current_path) ?>"><i class="<?= e($item['icon']) ?>"></i> <?= e($item['label']) ?></a>
        <?php endforeach; ?>

      <?php elseif ($inAdminSection): ?>
        <a href="<?= base_url('dashboard.php') ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> All Modules</a>
        <div class="nav-section"><i class="<?= e($ADMIN_ITEMS['icon']) ?>"></i> <?= e($ADMIN_ITEMS['label']) ?></div>
        <?php foreach ($ADMIN_ITEMS['items'] as $item): ?>
          <a href="<?= base_url($item['url']) ?>" class="<?= nav_active($item['match'], $current_path) ?>"><i class="<?= e($item['icon']) ?>"></i> <?= e($item['label']) ?></a>
        <?php endforeach; ?>

      <?php else: ?>
        <div class="nav-section">Modules</div>
        <?php foreach ($MODULES as $mod): ?>
          <a href="<?= base_url($mod['home']) ?>"><i class="<?= e($mod['icon']) ?>"></i> <?= e($mod['label']) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
  </aside>

  <div class="app-main">
    <header class="topbar">
      <button id="sidebarToggle" class="btn-icon" type="button" aria-label="Toggle menu"><i class="fa-solid fa-bars"></i></button>
      <div class="topbar-title"><?= isset($page_title) ? e($page_title) : '' ?></div>
      <div class="topbar-user dropdown">
        <button class="btn-icon dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="fa-solid fa-circle-user"></i> <?= e($user['name']) ?> <span class="badge text-bg-secondary text-capitalize"><?= e($user['role']) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php if (is_admin()): ?>
          <li><a class="dropdown-item" href="<?= base_url('users/users.php') ?>"><i class="fa-solid fa-users-gear"></i> Users</a></li>
          <li><a class="dropdown-item" href="<?= base_url('users/settings.php') ?>"><i class="fa-solid fa-gear"></i> Settings</a></li>
          <li><hr class="dropdown-divider"></li>
          <?php endif; ?>
          <li><a class="dropdown-item" href="<?= base_url('logout.php') ?>"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></li>
        </ul>
      </div>
    </header>
    <main class="content">
      <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
          <?= e($f['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endforeach; ?>
<?php else: ?>
<div class="auth-wrapper">
<?php endif; ?>
