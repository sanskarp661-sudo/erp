<?php
/**
 * Generic "Print Format" engine.
 *
 * A print format is a name + doctype + an HTML template stored in the
 * print_formats table. Templates use {{token}} placeholders that get
 * substituted with that record's data at print time (a mail-merge style
 * system, not a full expression language). print.php looks up which
 * format to use (an explicit ?format=, else the doctype's default, else
 * the built-in fallback below) and renders it.
 *
 * Adding a new printable doctype means adding one entry to PRINT_DOCTYPES
 * with a fetch() that returns the token values for a given record id.
 */

require_once __DIR__ . '/functions.php';

/** A few recent records of a doctype, for the format editor's preview picker. */
function pf_sample_records(string $doctype, int $limit = 20): array
{
    $queries = [
        'invoice'         => "SELECT i.id, CONCAT(i.invoice_no, ' — ', c.name) label FROM invoices i JOIN customers c ON c.id = i.customer_id ORDER BY i.id DESC LIMIT $limit",
        'product'         => "SELECT id, CONCAT(name, ' (', sku, ')') label FROM products ORDER BY id DESC LIMIT $limit",
        'customer'        => "SELECT id, name label FROM customers ORDER BY id DESC LIMIT $limit",
        'vendor'          => "SELECT id, name label FROM vendors ORDER BY id DESC LIMIT $limit",
        'sales_order'     => "SELECT so.id, CONCAT(so.order_no, ' — ', c.name) label FROM sales_orders so JOIN customers c ON c.id = so.customer_id ORDER BY so.id DESC LIMIT $limit",
        'purchase_order'  => "SELECT po.id, CONCAT(po.po_no, ' — ', v.name) label FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id ORDER BY po.id DESC LIMIT $limit",
        'salary_slip'     => "SELECT s.id, CONCAT(s.slip_no, ' — ', e.name) label FROM salary_slips s JOIN employees e ON e.id = s.employee_id ORDER BY s.id DESC LIMIT $limit",
    ];
    if (!isset($queries[$doctype])) {
        return [];
    }
    return db()->query($queries[$doctype])->fetchAll();
}

/** Builds a simple bordered HTML table from rows of already-formatted cell strings. */
function pf_table(array $columns, array $rows, string $emptyText = 'None'): string
{
    $thead = '<tr>';
    foreach ($columns as $col) {
        $align = $col['align'] ?? 'left';
        $thead .= '<th style="text-align:' . $align . '">' . e($col['label']) . '</th>';
    }
    $thead .= '</tr>';

    $tbody = '';
    foreach ($rows as $row) {
        $tbody .= '<tr>';
        foreach ($columns as $col) {
            $align = $col['align'] ?? 'left';
            $tbody .= '<td style="text-align:' . $align . '">' . $row[$col['key']] . '</td>';
        }
        $tbody .= '</tr>';
    }
    if (!$rows) {
        $tbody = '<tr><td colspan="' . count($columns) . '" style="text-align:center;color:#888">' . e($emptyText) . '</td></tr>';
    }

    return '<table class="pf-table"><thead>' . $thead . '</thead><tbody>' . $tbody . '</tbody></table>';
}

/** Substitutes {{token}} placeholders in a template with the given token values. */
function pf_render(string $template, array $tokens): string
{
    $pairs = [];
    foreach ($tokens as $key => $value) {
        $pairs['{{' . $key . '}}'] = $value;
    }
    return strtr($template, $pairs);
}

function pf_common_tokens(): array
{
    return [
        'company_name' => e(setting('company_name', APP_NAME)),
        'currency_symbol' => e(setting('currency_symbol', '$')),
        'today' => e(date('M j, Y')),
    ];
}

/**
 * doctype => [
 *   'label'    => display name shown throughout the UI,
 *   'roles'    => null (any logged-in user) or an array of allowed roles,
 *   'fetch'    => function(int $id): ?array — returns token values, or null if not found,
 *   'tokens'   => [token => human description] for the cheat-sheet in the format editor,
 *   'default'  => built-in fallback HTML template used when no admin-created default exists,
 * ]
 */
function pf_doctypes(): array
{
    static $doctypes = null;
    if ($doctypes !== null) {
        return $doctypes;
    }

    $doctypes = [
        'invoice' => [
            'label' => 'Sales Invoice',
            'roles' => null,
            'fetch' => function (int $id): ?array {
                $stmt = db()->prepare('SELECT i.*, c.name customer_name, c.email customer_email, c.phone customer_phone, c.address customer_address FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ?');
                $stmt->execute([$id]);
                $inv = $stmt->fetch();
                if (!$inv) return null;

                $items = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ?');
                $items->execute([$id]);
                $rows = array_map(fn($it) => [
                    'description' => e($it['description']),
                    'quantity' => (int)$it['quantity'],
                    'unit_price' => money($it['unit_price']),
                    'subtotal' => money($it['subtotal']),
                ], $items->fetchAll());

                return pf_common_tokens() + [
                    'invoice_no' => e($inv['invoice_no']),
                    'invoice_date' => e($inv['invoice_date']),
                    'due_date' => e($inv['due_date'] ?? ''),
                    'status' => e(str_replace('_', ' ', ucfirst($inv['status']))),
                    'customer_name' => e($inv['customer_name']),
                    'customer_email' => e($inv['customer_email'] ?? ''),
                    'customer_phone' => e($inv['customer_phone'] ?? ''),
                    'customer_address' => nl2br(e($inv['customer_address'] ?? '')),
                    'items_table' => pf_table(
                        [['key' => 'description', 'label' => 'Description'], ['key' => 'quantity', 'label' => 'Qty', 'align' => 'right'], ['key' => 'unit_price', 'label' => 'Unit Price', 'align' => 'right'], ['key' => 'subtotal', 'label' => 'Subtotal', 'align' => 'right']],
                        $rows
                    ),
                    'subtotal' => money($inv['subtotal']),
                    'tax' => money($inv['tax']),
                    'total' => money($inv['total']),
                    'amount_paid' => money($inv['amount_paid']),
                    'balance_due' => money($inv['total'] - $inv['amount_paid']),
                    'notes' => nl2br(e($inv['notes'] ?? '')),
                ];
            },
            'tokens' => [
                'invoice_no' => 'Invoice number', 'invoice_date' => 'Invoice date', 'due_date' => 'Due date', 'status' => 'Status',
                'customer_name' => 'Customer name', 'customer_email' => 'Customer email', 'customer_phone' => 'Customer phone', 'customer_address' => 'Customer address',
                'items_table' => 'Line items table', 'subtotal' => 'Subtotal', 'tax' => 'Tax', 'total' => 'Total',
                'amount_paid' => 'Amount paid', 'balance_due' => 'Balance due', 'notes' => 'Notes',
            ],
            'default' => pf_default_invoice_template(),
        ],

        'product' => [
            'label' => 'Item Master',
            'roles' => null,
            'fetch' => function (int $id): ?array {
                $stmt = db()->prepare('SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?');
                $stmt->execute([$id]);
                $p = $stmt->fetch();
                if (!$p) return null;

                return pf_common_tokens() + [
                    'sku' => e($p['sku']),
                    'name' => e($p['name']),
                    'category_name' => e($p['category_name'] ?? '—'),
                    'unit' => e($p['unit']),
                    'cost_price' => money($p['cost_price']),
                    'selling_price' => money($p['selling_price']),
                    'quantity' => (int)$p['quantity'],
                    'reorder_level' => (int)$p['reorder_level'],
                    'status' => e(ucfirst($p['status'])),
                ];
            },
            'tokens' => [
                'sku' => 'SKU', 'name' => 'Product name', 'category_name' => 'Category', 'unit' => 'Unit',
                'cost_price' => 'Cost price', 'selling_price' => 'Selling price', 'quantity' => 'Quantity in stock',
                'reorder_level' => 'Reorder level', 'status' => 'Status',
            ],
            'default' => pf_default_product_template(),
        ],

        'customer' => [
            'label' => 'Customer',
            'roles' => null,
            'fetch' => function (int $id): ?array {
                $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
                $stmt->execute([$id]);
                $c = $stmt->fetch();
                if (!$c) return null;

                $stats = db()->prepare("SELECT COUNT(*) orders, COALESCE(SUM(total_amount),0) total FROM sales_orders WHERE customer_id = ? AND status <> 'cancelled'");
                $stats->execute([$id]);
                $s = $stats->fetch();

                return pf_common_tokens() + [
                    'name' => e($c['name']),
                    'customer_company' => e($c['company'] ?? ''),
                    'email' => e($c['email'] ?? ''),
                    'phone' => e($c['phone'] ?? ''),
                    'address' => nl2br(e($c['address'] ?? '')),
                    'total_orders' => (int)$s['orders'],
                    'total_spent' => money($s['total']),
                ];
            },
            'tokens' => [
                'name' => 'Customer name', 'customer_company' => "Customer's company", 'email' => 'Email', 'phone' => 'Phone',
                'address' => 'Address', 'total_orders' => 'Total orders', 'total_spent' => 'Total spent',
            ],
            'default' => pf_default_party_template('Customer'),
        ],

        'vendor' => [
            'label' => 'Supplier',
            'roles' => null,
            'fetch' => function (int $id): ?array {
                $stmt = db()->prepare('SELECT * FROM vendors WHERE id = ?');
                $stmt->execute([$id]);
                $v = $stmt->fetch();
                if (!$v) return null;

                $stats = db()->prepare("SELECT COUNT(*) orders, COALESCE(SUM(total_amount),0) total FROM purchase_orders WHERE vendor_id = ? AND status <> 'cancelled'");
                $stats->execute([$id]);
                $s = $stats->fetch();

                return pf_common_tokens() + [
                    'name' => e($v['name']),
                    'customer_company' => e($v['company'] ?? ''),
                    'email' => e($v['email'] ?? ''),
                    'phone' => e($v['phone'] ?? ''),
                    'address' => nl2br(e($v['address'] ?? '')),
                    'total_orders' => (int)$s['orders'],
                    'total_spent' => money($s['total']),
                ];
            },
            'tokens' => [
                'name' => 'Supplier name', 'customer_company' => "Supplier's company", 'email' => 'Email', 'phone' => 'Phone',
                'address' => 'Address', 'total_orders' => 'Total orders', 'total_spent' => 'Total spent',
            ],
            'default' => pf_default_party_template('Supplier'),
        ],

        'sales_order' => [
            'label' => 'Sales Order',
            'roles' => null,
            'fetch' => function (int $id): ?array {
                $stmt = db()->prepare('SELECT so.*, c.name customer_name, c.email customer_email, c.phone customer_phone, c.address customer_address FROM sales_orders so JOIN customers c ON c.id = so.customer_id WHERE so.id = ?');
                $stmt->execute([$id]);
                $o = $stmt->fetch();
                if (!$o) return null;

                $items = db()->prepare('SELECT soi.*, p.name product_name, p.sku FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE order_id = ?');
                $items->execute([$id]);
                $rows = array_map(fn($it) => [
                    'description' => e($it['product_name']) . ' <span style="color:#888">(' . e($it['sku']) . ')</span>',
                    'quantity' => (int)$it['quantity'],
                    'unit_price' => money($it['unit_price']),
                    'subtotal' => money($it['subtotal']),
                ], $items->fetchAll());

                return pf_common_tokens() + [
                    'order_no' => e($o['order_no']),
                    'order_date' => e($o['order_date']),
                    'status' => e(ucfirst($o['status'])),
                    'customer_name' => e($o['customer_name']),
                    'customer_email' => e($o['customer_email'] ?? ''),
                    'customer_phone' => e($o['customer_phone'] ?? ''),
                    'customer_address' => nl2br(e($o['customer_address'] ?? '')),
                    'items_table' => pf_table(
                        [['key' => 'description', 'label' => 'Product'], ['key' => 'quantity', 'label' => 'Qty', 'align' => 'right'], ['key' => 'unit_price', 'label' => 'Unit Price', 'align' => 'right'], ['key' => 'subtotal', 'label' => 'Subtotal', 'align' => 'right']],
                        $rows
                    ),
                    'total' => money($o['total_amount']),
                    'notes' => nl2br(e($o['notes'] ?? '')),
                ];
            },
            'tokens' => [
                'order_no' => 'Order number', 'order_date' => 'Order date', 'status' => 'Status',
                'customer_name' => 'Customer name', 'customer_email' => 'Customer email', 'customer_phone' => 'Customer phone', 'customer_address' => 'Customer address',
                'items_table' => 'Line items table', 'total' => 'Total', 'notes' => 'Notes',
            ],
            'default' => pf_default_order_template('Sales Order', '{{order_no}}', '{{customer_name}}'),
        ],

        'purchase_order' => [
            'label' => 'Purchase Order',
            'roles' => null,
            'fetch' => function (int $id): ?array {
                $stmt = db()->prepare('SELECT po.*, v.name vendor_name, v.email vendor_email, v.phone vendor_phone, v.address vendor_address FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id WHERE po.id = ?');
                $stmt->execute([$id]);
                $o = $stmt->fetch();
                if (!$o) return null;

                $items = db()->prepare('SELECT poi.*, p.name product_name, p.sku FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE po_id = ?');
                $items->execute([$id]);
                $rows = array_map(fn($it) => [
                    'description' => e($it['product_name']) . ' <span style="color:#888">(' . e($it['sku']) . ')</span>',
                    'quantity' => (int)$it['quantity'],
                    'unit_price' => money($it['unit_cost']),
                    'subtotal' => money($it['subtotal']),
                ], $items->fetchAll());

                return pf_common_tokens() + [
                    'order_no' => e($o['po_no']),
                    'order_date' => e($o['order_date']),
                    'status' => e(ucfirst($o['status'])),
                    'customer_name' => e($o['vendor_name']),
                    'customer_email' => e($o['vendor_email'] ?? ''),
                    'customer_phone' => e($o['vendor_phone'] ?? ''),
                    'customer_address' => nl2br(e($o['vendor_address'] ?? '')),
                    'items_table' => pf_table(
                        [['key' => 'description', 'label' => 'Product'], ['key' => 'quantity', 'label' => 'Qty', 'align' => 'right'], ['key' => 'unit_price', 'label' => 'Unit Cost', 'align' => 'right'], ['key' => 'subtotal', 'label' => 'Subtotal', 'align' => 'right']],
                        $rows
                    ),
                    'total' => money($o['total_amount']),
                    'notes' => nl2br(e($o['notes'] ?? '')),
                ];
            },
            'tokens' => [
                'order_no' => 'PO number', 'order_date' => 'Order date', 'status' => 'Status',
                'customer_name' => 'Vendor name', 'customer_email' => 'Vendor email', 'customer_phone' => 'Vendor phone', 'customer_address' => 'Vendor address',
                'items_table' => 'Line items table', 'total' => 'Total', 'notes' => 'Notes',
            ],
            'default' => pf_default_order_template('Purchase Order', '{{order_no}}', '{{customer_name}}'),
        ],

        'salary_slip' => [
            'label' => 'Salary Slip',
            'roles' => ['admin', 'manager'],
            'fetch' => function (int $id): ?array {
                $stmt = db()->prepare("
                  SELECT s.*, e.name employee_name, e.employee_code, e.designation, d.name department_name
                  FROM salary_slips s JOIN employees e ON e.id = s.employee_id LEFT JOIN departments d ON d.id = e.department_id
                  WHERE s.id = ?
                ");
                $stmt->execute([$id]);
                $s = $stmt->fetch();
                if (!$s) return null;

                $items = db()->prepare('SELECT * FROM salary_slip_items WHERE salary_slip_id = ? ORDER BY id');
                $items->execute([$id]);
                $all = $items->fetchAll();
                $earnRows = [['description' => 'Basic Salary', 'amount' => money($s['basic_salary'])]];
                foreach ($all as $it) {
                    if ($it['component_type'] === 'earning') $earnRows[] = ['description' => e($it['label']), 'amount' => money($it['amount'])];
                }
                $dedRows = [];
                foreach ($all as $it) {
                    if ($it['component_type'] === 'deduction') $dedRows[] = ['description' => e($it['label']), 'amount' => money($it['amount'])];
                }
                $cols = [['key' => 'description', 'label' => 'Component'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right']];

                return pf_common_tokens() + [
                    'slip_no' => e($s['slip_no']),
                    'employee_name' => e($s['employee_name']),
                    'employee_code' => e($s['employee_code']),
                    'designation' => e($s['designation'] ?? ''),
                    'department_name' => e($s['department_name'] ?? ''),
                    'pay_period' => e(date('M j', strtotime($s['pay_period_start']))) . ' &ndash; ' . e(date('M j, Y', strtotime($s['pay_period_end']))),
                    'status' => e(ucfirst($s['status'])),
                    'earnings_table' => pf_table($cols, $earnRows),
                    'deductions_table' => pf_table($cols, $dedRows),
                    'total_earnings' => money($s['total_earnings']),
                    'total_deductions' => money($s['total_deductions']),
                    'net_pay' => money($s['net_pay']),
                ];
            },
            'tokens' => [
                'slip_no' => 'Slip number', 'employee_name' => 'Employee name', 'employee_code' => 'Employee code',
                'designation' => 'Designation', 'department_name' => 'Department', 'pay_period' => 'Pay period', 'status' => 'Status',
                'earnings_table' => 'Earnings table', 'deductions_table' => 'Deductions table',
                'total_earnings' => 'Total earnings', 'total_deductions' => 'Total deductions', 'net_pay' => 'Net pay',
            ],
            'default' => pf_default_salary_slip_template(),
        ],
    ];

    return $doctypes;
}

function pf_default_invoice_template(): string
{
    return <<<HTML
<div class="pf-header">
  <div><div class="pf-brand">{{company_name}}</div><div class="pf-subtitle">Invoice</div></div>
  <div class="pf-right"><div class="pf-doc-no">{{invoice_no}}</div><div>Date: {{invoice_date}}</div><div>Due: {{due_date}}</div></div>
</div>
<div class="pf-section"><strong>Bill To:</strong><br>{{customer_name}}<br>{{customer_address}}<br>{{customer_email}} {{customer_phone}}</div>
{{items_table}}
<table class="pf-totals">
  <tr><th>Subtotal</th><td>{{subtotal}}</td></tr>
  <tr><th>Tax</th><td>{{tax}}</td></tr>
  <tr><th>Total</th><td>{{total}}</td></tr>
  <tr><th>Paid</th><td>{{amount_paid}}</td></tr>
  <tr class="pf-highlight"><th>Balance Due</th><td>{{balance_due}}</td></tr>
</table>
<div class="pf-section">{{notes}}</div>
HTML;
}

function pf_default_product_template(): string
{
    return <<<HTML
<div class="pf-header">
  <div><div class="pf-brand">{{company_name}}</div><div class="pf-subtitle">Item Card</div></div>
  <div class="pf-right"><div class="pf-doc-no">{{sku}}</div></div>
</div>
<table class="pf-totals">
  <tr><th>Name</th><td>{{name}}</td></tr>
  <tr><th>Category</th><td>{{category_name}}</td></tr>
  <tr><th>Unit</th><td>{{unit}}</td></tr>
  <tr><th>Cost Price</th><td>{{cost_price}}</td></tr>
  <tr><th>Selling Price</th><td>{{selling_price}}</td></tr>
  <tr><th>Quantity in Stock</th><td>{{quantity}}</td></tr>
  <tr><th>Reorder Level</th><td>{{reorder_level}}</td></tr>
  <tr><th>Status</th><td>{{status}}</td></tr>
</table>
HTML;
}

function pf_default_party_template(string $label): string
{
    return <<<HTML
<div class="pf-header">
  <div><div class="pf-brand">{{company_name}}</div><div class="pf-subtitle">{$label}</div></div>
</div>
<table class="pf-totals">
  <tr><th>Name</th><td>{{name}}</td></tr>
  <tr><th>Company</th><td>{{customer_company}}</td></tr>
  <tr><th>Email</th><td>{{email}}</td></tr>
  <tr><th>Phone</th><td>{{phone}}</td></tr>
  <tr><th>Address</th><td>{{address}}</td></tr>
  <tr><th>Total Orders</th><td>{{total_orders}}</td></tr>
  <tr><th>Total Spent</th><td>{{total_spent}}</td></tr>
</table>
HTML;
}

function pf_default_order_template(string $label, string $noToken, string $partyToken): string
{
    return <<<HTML
<div class="pf-header">
  <div><div class="pf-brand">{{company_name}}</div><div class="pf-subtitle">{$label}</div></div>
  <div class="pf-right"><div class="pf-doc-no">{$noToken}</div><div>Date: {{order_date}}</div><div>Status: {{status}}</div></div>
</div>
<div class="pf-section"><strong>Party:</strong><br>{$partyToken}<br>{{customer_address}}<br>{{customer_email}} {{customer_phone}}</div>
{{items_table}}
<table class="pf-totals"><tr class="pf-highlight"><th>Total</th><td>{{total}}</td></tr></table>
<div class="pf-section">{{notes}}</div>
HTML;
}

function pf_default_salary_slip_template(): string
{
    return <<<HTML
<div class="pf-header">
  <div><div class="pf-brand">{{company_name}}</div><div class="pf-subtitle">Payslip</div></div>
  <div class="pf-right"><div class="pf-doc-no">{{slip_no}}</div><div>Period: {{pay_period}}</div><div>Status: {{status}}</div></div>
</div>
<div class="pf-section"><strong>Employee:</strong> {{employee_name}} ({{employee_code}})<br>{{designation}} &middot; {{department_name}}</div>
{{earnings_table}}
{{deductions_table}}
<table class="pf-totals">
  <tr><th>Total Earnings</th><td>{{total_earnings}}</td></tr>
  <tr><th>Total Deductions</th><td>{{total_deductions}}</td></tr>
  <tr class="pf-highlight"><th>Net Pay</th><td>{{net_pay}}</td></tr>
</table>
HTML;
}
