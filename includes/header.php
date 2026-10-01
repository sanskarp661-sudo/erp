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
// A page outside a module folder (e.g. a shared report) can pick its sidebar with $sidebar_module.
$currentModuleKey = isset($sidebar_module, $MODULES[$sidebar_module]) ? $sidebar_module : current_module_key($current_path);
$inAdminSection = false;
foreach ((array)$ADMIN_ITEMS['match'] as $adminMatch) {
    if (str_contains($current_path, $adminMatch)) { $inAdminSection = true; break; }
}

function nav_active($needle, string $current): string
{
    foreach ((array)$needle as $n) {
        if (str_contains($current, $n)) return 'active';
    }
    return '';
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
<link href="<?= asset_url('assets/css/style.css') ?>" rel="stylesheet">
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

      <?php if (!empty($sidebar_subnav)): ?>
        <a href="<?= base_url($sidebar_subnav['back_url']) ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= e($sidebar_subnav['back_label']) ?></a>
        <div class="nav-section"><i class="<?= e($sidebar_subnav['icon']) ?>"></i> <?= e($sidebar_subnav['label']) ?></div>
        <?php foreach ($sidebar_subnav['items'] as $item): ?>
          <a href="<?= base_url($item['url']) ?>" class="<?= !empty($item['active']) ? 'active' : '' ?>"><i class="<?= e($item['icon']) ?>"></i> <?= e($item['label']) ?></a>
        <?php endforeach; ?>

      <?php elseif ($currentModuleKey): ?>
        <?php $mod = $MODULES[$currentModuleKey]; ?>
        <a href="<?= base_url('dashboard.php') ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> All Modules</a>
        <div class="nav-section"><i class="<?= e($mod['icon']) ?>"></i> <?= e($mod['label']) ?></div>
        <?php foreach ($mod['items'] as $item): ?>
          <?php if (isset($item['visible']) && !$item['visible']) continue; ?>
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
      <?php if (!empty($dashboard_topbar)): ?>
      <form class="topbar-search" action="<?= base_url('search.php') ?>" method="get" role="search" autocomplete="off">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" name="q" id="globalSearch" placeholder="Search anything... (Products, Customers, Orders, etc.)" aria-label="Search">
        <kbd>Ctrl + K</kbd>
        <div class="topbar-search-results" id="globalSearchResults" hidden></div>
      </form>
      <div class="topbar-spacer"></div>
      <div class="dropdown">
        <button class="btn-icon topbar-bell" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
          <i class="fa-regular fa-bell"></i>
          <?php if (!empty($dashboard_notifications)): ?><span class="topbar-bell-dot"></span><?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end topbar-notifications">
          <div class="px-3 py-2 fw-semibold border-bottom">Notifications</div>
          <?php foreach ((array)($dashboard_notifications ?? []) as $n): ?>
            <a class="dropdown-item d-flex align-items-center gap-2 py-2" href="<?= e($n['url']) ?>">
              <span class="fin-kpi-icon <?= e($n['tone']) ?>" style="width:32px;height:32px;font-size:.9rem"><i class="<?= e($n['icon']) ?>"></i></span>
              <span class="small text-wrap"><?= e($n['text']) ?></span>
            </a>
          <?php endforeach; ?>
          <?php if (empty($dashboard_notifications)): ?>
            <div class="px-3 py-3 small text-muted">You're all caught up.</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="dropdown">
        <button class="topbar-store dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="fa-solid fa-store"></i> <span><?= e($dashboard_store_name ?? 'All Stores') ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item <?= empty($dashboard_store_id) ? 'active' : '' ?>" href="<?= base_url('dashboard.php?store=0') ?>">All Stores</a></li>
          <?php if (!empty($dashboard_stores)): ?><li><hr class="dropdown-divider"></li><?php endif; ?>
          <?php foreach ((array)($dashboard_stores ?? []) as $s): ?>
            <li><a class="dropdown-item <?= (int)$s['id'] === (int)($dashboard_store_id ?? 0) ? 'active' : '' ?>" href="<?= base_url('dashboard.php?store=' . (int)$s['id']) ?>"><?= e($s['name']) ?></a></li>
          <?php endforeach; ?>
          <?php if (empty($dashboard_stores)): ?>
            <li><a class="dropdown-item small text-muted" href="<?= base_url('supply-chain/warehouses.php') ?>">No stores yet. Add a warehouse</a></li>
          <?php endif; ?>
        </ul>
      </div>
      <?php elseif (!empty($pos_topbar)): ?>
      <?php
        $posSwitch = function (string $key, int $id): string {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
            $q[$key] = $id;
            return e(parse_url($uri, PHP_URL_PATH) . '?' . http_build_query($q));
        };
      ?>
      <div class="topbar-title">POS</div>
      <div class="dropdown">
        <button class="pos-top-select dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Store">
          <i class="fa-solid fa-location-dot text-primary"></i> <span class="d-none d-md-inline"><strong>Store:</strong> <span class="text-muted"><?= e($pos_topbar['store_name']) ?></span></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php foreach ($pos_topbar['stores'] as $posStore): ?>
            <li><a class="dropdown-item <?= (int)$posStore['id'] === (int)$pos_topbar['warehouse_id'] ? 'active' : '' ?>" href="<?= $posSwitch('pos_store', (int)$posStore['id']) ?>"><?= e($posStore['name']) ?></a></li>
          <?php endforeach; ?>
          <?php if (!$pos_topbar['stores']): ?><li><span class="dropdown-item small text-muted">No stores yet</span></li><?php endif; ?>
        </ul>
      </div>
      <div class="dropdown">
        <button class="pos-top-select dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Terminal">
          <i class="fa-solid fa-desktop text-primary"></i> <span><?= e($pos_topbar['profile']['name'] ?? 'No terminal') ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php foreach ($pos_topbar['profiles'] as $posProf): ?>
            <li><a class="dropdown-item <?= (int)$posProf['id'] === (int)$pos_topbar['profile_id'] ? 'active' : '' ?>" href="<?= $posSwitch('pos_terminal', (int)$posProf['id']) ?>"><?= e($posProf['name']) ?> <small class="text-muted"><?= e($posProf['warehouse_name'] ?? '') ?></small></a></li>
          <?php endforeach; ?>
          <?php if (can_manage_module('pos')): ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item small" href="<?= base_url('pos/settings.php?s=profiles') ?>">Manage terminals</a></li>
          <?php endif; ?>
        </ul>
      </div>
      <span class="pos-online" id="posOnline" data-currency="<?= e(setting('currency_symbol', '$')) ?>" data-inr="<?= setting('currency_code', 'INR') === 'INR' ? '1' : '0' ?>"><span class="dot"></span> <span class="txt">Online</span></span>
      <?php else: ?>
      <div class="topbar-title"><?= isset($page_title) ? e($page_title) : '' ?></div>
      <?php endif; ?>
      <div class="topbar-user dropdown">
        <?php if (!empty($dashboard_topbar) || !empty($pos_topbar)): ?>
        <button class="topbar-profile dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <span class="topbar-avatar"><?php if (!empty($user['image'])): ?><img src="<?= base_url($user['image']) ?>" alt=""><?php else: ?><?= e(strtoupper(mb_substr(trim($user['name']), 0, 1))) ?><?php endif; ?></span>
          <span class="topbar-profile-text">
            <strong><?= e($user['name']) ?></strong>
            <small><?= e(implode(', ', array_map('role_label', $user['roles'] ?? []))) ?: 'No roles' ?></small>
          </span>
        </button>
        <?php else: ?>
        <button class="btn-icon dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="fa-solid fa-circle-user"></i> <?= e($user['name']) ?> <span class="badge text-bg-secondary"><?= e(implode(', ', array_map('role_label', $user['roles'] ?? []))) ?: 'No roles' ?></span>
        </button>
        <?php endif; ?>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php if (can_view_admin_section()): ?>
          <li><a class="dropdown-item" href="<?= base_url('users/user_form.php?id=' . (int)$user['id']) ?>"><i class="fa-solid fa-id-card"></i> My Profile</a></li>
          <li><a class="dropdown-item" href="<?= base_url('users/users.php') ?>"><i class="fa-solid fa-users-gear"></i> Users</a></li>
          <li><a class="dropdown-item" href="<?= base_url('users/settings.php') ?>"><i class="fa-solid fa-gear"></i> Settings</a></li>
          <li><a class="dropdown-item" href="<?= base_url('users/integrations.php') ?>"><i class="fa-solid fa-plug"></i> Integrations</a></li>
          <li><a class="dropdown-item" href="<?= base_url('print_formats/index.php') ?>"><i class="fa-solid fa-palette"></i> Print Formats</a></li>
          <li><a class="dropdown-item" href="<?= base_url('imports/index.php') ?>"><i class="fa-solid fa-file-import"></i> Data Import</a></li>
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
