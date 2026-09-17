<?php
/**
 * Shared page chrome (top of page). Requires includes/auth.php to already
 * be loaded and require_login() to have been called by the page.
 * A page may set $page_title before including this file.
 */
$user = current_user();
$current_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

function nav_active(string $needle, string $current): string
{
    return str_starts_with($current, $needle) ? 'active' : '';
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
      <a href="<?= base_url('dashboard.php') ?>" class="<?= nav_active('/dashboard.php', $current_path) ?>"><i class="fa-solid fa-gauge"></i> Dashboard</a>

      <div class="nav-section">Inventory</div>
      <a href="<?= base_url('inventory/products.php') ?>" class="<?= nav_active('/inventory/product', $current_path) ?>"><i class="fa-solid fa-box"></i> Products</a>
      <a href="<?= base_url('inventory/categories.php') ?>" class="<?= nav_active('/inventory/categ', $current_path) ?>"><i class="fa-solid fa-tags"></i> Categories</a>
      <a href="<?= base_url('inventory/stock_movements.php') ?>" class="<?= nav_active('/inventory/stock', $current_path) ?>"><i class="fa-solid fa-arrow-right-arrow-left"></i> Stock Movements</a>

      <div class="nav-section">Sales</div>
      <a href="<?= base_url('sales/orders.php') ?>" class="<?= nav_active('/sales/order', $current_path) ?>"><i class="fa-solid fa-cart-shopping"></i> Sales Orders</a>
      <a href="<?= base_url('sales/customers.php') ?>" class="<?= nav_active('/sales/customer', $current_path) ?>"><i class="fa-solid fa-address-book"></i> Customers</a>

      <div class="nav-section">Purchases</div>
      <a href="<?= base_url('purchases/orders.php') ?>" class="<?= nav_active('/purchases/order', $current_path) ?>"><i class="fa-solid fa-truck-field"></i> Purchase Orders</a>
      <a href="<?= base_url('purchases/vendors.php') ?>" class="<?= nav_active('/purchases/vendor', $current_path) ?>"><i class="fa-solid fa-handshake"></i> Vendors</a>

      <div class="nav-section">Accounting</div>
      <a href="<?= base_url('accounting/invoices.php') ?>" class="<?= nav_active('/accounting/invoice', $current_path) ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
      <a href="<?= base_url('accounting/payments.php') ?>" class="<?= nav_active('/accounting/payment', $current_path) ?>"><i class="fa-solid fa-money-check-dollar"></i> Payments</a>
      <a href="<?= base_url('accounting/expenses.php') ?>" class="<?= nav_active('/accounting/expense', $current_path) ?>"><i class="fa-solid fa-receipt"></i> Expenses</a>

      <div class="nav-section">HR</div>
      <a href="<?= base_url('hr/employees.php') ?>" class="<?= nav_active('/hr/employee', $current_path) ?>"><i class="fa-solid fa-user-tie"></i> Employees</a>
      <a href="<?= base_url('hr/departments.php') ?>" class="<?= nav_active('/hr/department', $current_path) ?>"><i class="fa-solid fa-sitemap"></i> Departments</a>
      <a href="<?= base_url('hr/attendance.php') ?>" class="<?= nav_active('/hr/attendance', $current_path) ?>"><i class="fa-solid fa-calendar-check"></i> Attendance</a>
      <a href="<?= base_url('hr/leaves.php') ?>" class="<?= nav_active('/hr/leave', $current_path) ?>"><i class="fa-solid fa-plane-departure"></i> Leaves</a>

      <div class="nav-section">Reports</div>
      <a href="<?= base_url('reports/sales_report.php') ?>" class="<?= nav_active('/reports/sales', $current_path) ?>"><i class="fa-solid fa-chart-line"></i> Sales Report</a>
      <a href="<?= base_url('reports/inventory_report.php') ?>" class="<?= nav_active('/reports/inventory', $current_path) ?>"><i class="fa-solid fa-warehouse"></i> Inventory Report</a>
      <a href="<?= base_url('reports/purchase_report.php') ?>" class="<?= nav_active('/reports/purchase', $current_path) ?>"><i class="fa-solid fa-cart-flatbed"></i> Purchase Report</a>
      <a href="<?= base_url('reports/financial_report.php') ?>" class="<?= nav_active('/reports/financial', $current_path) ?>"><i class="fa-solid fa-sack-dollar"></i> Financial Report</a>

      <?php if (is_admin()): ?>
      <div class="nav-section">Administration</div>
      <a href="<?= base_url('users/users.php') ?>" class="<?= nav_active('/users/', $current_path) ?>"><i class="fa-solid fa-users-gear"></i> Users</a>
      <a href="<?= base_url('users/settings.php') ?>" class="<?= nav_active('/users/settings', $current_path) ?>"><i class="fa-solid fa-gear"></i> Settings</a>
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
