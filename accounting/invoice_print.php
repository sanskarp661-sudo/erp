<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)input('id');
$stmt = db()->prepare('SELECT i.*, c.name customer_name, c.email customer_email, c.phone customer_phone, c.address customer_address FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ?');
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die('Invoice not found.');
}

$items = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$balance = $invoice['total'] - $invoice['amount_paid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice <?= e($invoice['invoice_no']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { padding: 40px; color: #1f2937; }
  .brand { font-size: 1.4rem; font-weight: 700; }
  @media print { .no-print { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="container">
  <div class="d-flex justify-content-between align-items-start mb-4">
    <div>
      <div class="brand"><?= e(setting('company_name', APP_NAME)) ?></div>
      <div class="text-muted">Invoice</div>
    </div>
    <div class="text-end">
      <div class="fs-4 fw-bold"><?= e($invoice['invoice_no']) ?></div>
      <div>Date: <?= e($invoice['invoice_date']) ?></div>
      <?php if ($invoice['due_date']): ?><div>Due: <?= e($invoice['due_date']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="mb-4">
    <strong>Bill To:</strong><br>
    <?= e($invoice['customer_name']) ?><br>
    <?php if ($invoice['customer_address']): ?><?= nl2br(e($invoice['customer_address'])) ?><br><?php endif; ?>
    <?php if ($invoice['customer_email']): ?><?= e($invoice['customer_email']) ?><br><?php endif; ?>
    <?php if ($invoice['customer_phone']): ?><?= e($invoice['customer_phone']) ?><?php endif; ?>
  </div>

  <table class="table table-bordered">
    <thead class="table-light"><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['description']) ?></td>
        <td class="text-end"><?= (int)$it['quantity'] ?></td>
        <td class="text-end"><?= money($it['unit_price']) ?></td>
        <td class="text-end"><?= money($it['subtotal']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="row">
    <div class="col-md-6"></div>
    <div class="col-md-6">
      <table class="table">
        <tr><th>Subtotal</th><td class="text-end"><?= money($invoice['subtotal']) ?></td></tr>
        <tr><th>Tax</th><td class="text-end"><?= money($invoice['tax']) ?></td></tr>
        <tr><th>Total</th><td class="text-end fw-bold"><?= money($invoice['total']) ?></td></tr>
        <tr><th>Paid</th><td class="text-end"><?= money($invoice['amount_paid']) ?></td></tr>
        <tr class="table-warning"><th>Balance Due</th><td class="text-end fw-bold"><?= money($balance) ?></td></tr>
      </table>
    </div>
  </div>

  <?php if ($invoice['notes']): ?><p><strong>Notes:</strong> <?= nl2br(e($invoice['notes'])) ?></p><?php endif; ?>

  <div class="no-print mt-4">
    <button class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    <?php if (input('pos') === '1'): ?>
      <a href="<?= base_url('pos/index.php') ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-cash-register"></i> New Sale</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
