<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
pos_require_schema();

$id = (int)input('id');
$html = pos_receipt_html($id);
if ($html === '') {
    http_response_code(404);
    exit('Receipt not found.');
}
$width = pos_setting('pos_receipt_width') === '58' ? '58mm' : '80mm';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt</title>
<style>
  @page { size: <?= $width ?> auto; margin: 3mm; }
  body { margin: 0; padding: 10px; background: #fff; }
  .bar { text-align: center; margin-bottom: 12px; font-family: system-ui, sans-serif; }
  .bar button, .bar a { padding: 6px 14px; margin: 0 4px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; font-size: 14px; color: #1f2937; text-decoration: none; }
  @media print { .bar { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="bar"><button onclick="window.print()">Print</button><a href="javascript:window.close()">Close</a></div>
<?= $html ?>
<?php if (input('autoprint') === '1'): ?><script>window.addEventListener('load', function () { window.print(); });</script><?php endif; ?>
</body>
</html>
