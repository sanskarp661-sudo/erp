<?php
/**
 * Defines the top-level ERP modules and the feature/doctype links that
 * appear in each module's own sidebar. includes/header.php uses this to
 * decide whether to show the module list (top level) or a module's
 * feature list (once inside a module), based on the current URL path.
 *
 * Declared `global` explicitly: includes/permissions.php's deny_access()
 * renders the 403 page by `require`-ing header.php from inside a function
 * body, so without this, a plain top-level `$MODULES = [...]` would land
 * in that function's local scope instead of the global scope
 * current_module_key() reads from.
 */
global $MODULES, $ADMIN_ITEMS;

$MODULES = [
    'inventory' => [
        'label' => 'Inventory',
        'color' => '#2f6fed',
        'icon'  => 'fa-solid fa-box',
        'home'  => 'inventory/index.php',
        'match' => '/inventory/',
        'items' => [
            ['label' => 'Dashboard',  'icon' => 'fa-solid fa-gauge',        'url' => 'inventory/index.php',            'match' => 'inventory/index.php'],
            ['label' => 'Products',   'icon' => 'fa-solid fa-box',          'url' => 'inventory/products.php',         'match' => 'inventory/product'],
            ['label' => 'Categories', 'icon' => 'fa-solid fa-tags',         'url' => 'inventory/categories.php',       'match' => 'inventory/categ'],
            ['label' => 'Item Categories', 'icon' => 'fa-solid fa-layer-group', 'url' => 'inventory/item_categories.php', 'match' => 'inventory/item_categ'],
            ['label' => 'Brands',     'icon' => 'fa-solid fa-copyright',    'url' => 'inventory/brands.php',           'match' => 'inventory/brand'],
            ['label' => 'Units of Measure', 'icon' => 'fa-solid fa-ruler',  'url' => 'inventory/uom.php',               'match' => 'inventory/uom'],
            ['label' => 'Stock Balance', 'icon' => 'fa-solid fa-scale-balanced', 'url' => 'reports/stock_balance_report.php', 'match' => 'reports/stock_balance'],
            ['label' => 'Inventory Report', 'icon' => 'fa-solid fa-chart-line', 'url' => 'reports/inventory_report.php', 'match' => 'reports/inventory'],
        ],
    ],
    'supply-chain' => [
        'label' => 'Supply Chain',
        'color' => '#0891b2',
        'icon'  => 'fa-solid fa-truck-ramp-box',
        'home'  => 'supply-chain/index.php',
        'match' => '/supply-chain/',
        'items' => [
            ['label' => 'Dashboard',        'icon' => 'fa-solid fa-gauge',                  'url' => 'supply-chain/index.php',          'match' => 'supply-chain/index.php'],
            ['label' => 'Warehouses',       'icon' => 'fa-solid fa-warehouse',              'url' => 'supply-chain/warehouses.php',      'match' => 'supply-chain/warehouse'],
            ['label' => 'Stock Movements',  'icon' => 'fa-solid fa-arrow-right-arrow-left', 'url' => 'supply-chain/stock_movements.php', 'match' => 'supply-chain/stock_movements'],
            ['label' => 'Stock Balance',    'icon' => 'fa-solid fa-scale-balanced',         'url' => 'reports/stock_balance_report.php', 'match' => 'reports/stock_balance'],
            ['label' => 'Stock Entries',    'icon' => 'fa-solid fa-dolly',                  'url' => 'supply-chain/stock_entries.php',   'match' => 'supply-chain/stock_entries'],
            ['label' => 'New Stock Entry',  'icon' => 'fa-solid fa-plus',                   'url' => 'supply-chain/stock_entry_form.php', 'match' => 'supply-chain/stock_entry_form'],
        ],
    ],
    'procurement' => [
        'label' => 'Procurement',
        'color' => '#ea580c',
        'icon'  => 'fa-solid fa-truck-field',
        'home'  => 'purchases/index.php',
        'match' => '/purchases/',
        'items' => [
            ['label' => 'Dashboard',        'icon' => 'fa-solid fa-gauge',   'url' => 'purchases/index.php',  'match' => 'purchases/index.php'],
            ['label' => 'Purchase Orders',  'icon' => 'fa-solid fa-file-invoice', 'url' => 'purchases/orders.php', 'match' => 'purchases/order'],
            ['label' => 'Goods Receipts',   'icon' => 'fa-solid fa-box-open', 'url' => 'purchases/grns.php', 'match' => 'purchases/grn'],
            ['label' => 'Purchase Returns', 'icon' => 'fa-solid fa-rotate-left', 'url' => 'purchases/returns.php', 'match' => 'purchases/return'],
            ['label' => 'Purchase Invoices','icon' => 'fa-solid fa-file-invoice-dollar', 'url' => 'accounting/purchase_invoices.php', 'match' => 'accounting/purchase_invoice'],
            ['label' => 'Vendors',          'icon' => 'fa-solid fa-handshake', 'url' => 'purchases/vendors.php', 'match' => 'purchases/vendor'],
            ['label' => 'Purchase Report',  'icon' => 'fa-solid fa-chart-line', 'url' => 'reports/purchase_report.php', 'match' => 'reports/purchase'],
        ],
    ],
    'sales' => [
        'label' => 'Sales',
        'color' => '#16a34a',
        'icon'  => 'fa-solid fa-cart-shopping',
        'home'  => 'sales/index.php',
        'match' => '/sales/',
        'items' => [
            ['label' => 'Dashboard',      'icon' => 'fa-solid fa-gauge',      'url' => 'sales/index.php',  'match' => 'sales/index.php'],
            ['label' => 'Sales Orders',   'icon' => 'fa-solid fa-cart-shopping', 'url' => 'sales/orders.php', 'match' => 'sales/order'],
            ['label' => 'Quotations',     'icon' => 'fa-solid fa-file-lines', 'url' => 'sales/quotations.php', 'match' => 'sales/quotation'],
            ['label' => 'Delivery Notes', 'icon' => 'fa-solid fa-truck',      'url' => 'sales/delivery_notes.php', 'match' => 'sales/delivery_note'],
            ['label' => 'Sales Returns',  'icon' => 'fa-solid fa-rotate-left', 'url' => 'sales/returns.php', 'match' => 'sales/return'],
            ['label' => 'Sales Invoices', 'icon' => 'fa-solid fa-file-invoice-dollar', 'url' => 'accounting/invoices.php', 'match' => 'accounting/invoice'],
            ['label' => 'Price Lists',    'icon' => 'fa-solid fa-tags',       'url' => 'sales/price_lists.php', 'match' => 'sales/price_list'],
            ['label' => 'Tax Templates',  'icon' => 'fa-solid fa-percent',    'url' => 'sales/tax_templates.php', 'match' => 'sales/tax_template'],
            ['label' => 'Shipping Partners', 'icon' => 'fa-solid fa-truck-fast', 'url' => 'sales/shipping_partners.php', 'match' => 'sales/shipping_partner'],
            ['label' => 'Payment Terms',  'icon' => 'fa-solid fa-file-contract', 'url' => 'sales/payment_terms_templates.php', 'match' => 'sales/payment_terms'],
            ['label' => 'Sales Report',   'icon' => 'fa-solid fa-chart-line', 'url' => 'reports/sales_report.php', 'match' => 'reports/sales'],
        ],
    ],
    'pos' => [
        'label' => 'POS',
        'color' => '#7c3aed',
        'icon'  => 'fa-solid fa-cash-register',
        'home'  => 'pos/index.php',
        'match' => '/pos/',
        'items' => [
            ['label' => 'New Sale',      'icon' => 'fa-solid fa-cash-register', 'url' => 'pos/index.php',   'match' => 'pos/index.php'],
            ['label' => 'POS Orders',    'icon' => 'fa-solid fa-receipt',       'url' => 'pos/orders.php',  'match' => 'pos/order'],
        ],
    ],
    'hrms' => [
        'label' => 'HRMS',
        'color' => '#db2777',
        'icon'  => 'fa-solid fa-user-tie',
        'home'  => 'hr/index.php',
        'match' => '/hr/',
        'items' => [
            ['label' => 'Dashboard',    'icon' => 'fa-solid fa-gauge',           'url' => 'hr/index.php',       'match' => 'hr/index.php'],
            ['label' => 'Employees',    'icon' => 'fa-solid fa-user-tie',        'url' => 'hr/employees.php',   'match' => 'hr/employee'],
            ['label' => 'Departments',  'icon' => 'fa-solid fa-sitemap',         'url' => 'hr/departments.php', 'match' => 'hr/department'],
            ['label' => 'Attendance',   'icon' => 'fa-solid fa-calendar-check',  'url' => 'hr/attendance.php',  'match' => 'hr/attendance'],
            ['label' => 'Leaves',       'icon' => 'fa-solid fa-plane-departure', 'url' => 'hr/leaves.php',      'match' => 'hr/leave'],
            ['label' => 'Salary Slips', 'icon' => 'fa-solid fa-money-check-dollar', 'url' => 'hr/salary_slips.php', 'match' => 'hr/salary_slip', 'visible' => can_manage_module('hrms')],
        ],
    ],
    'crm' => [
        'label' => 'CRM',
        'color' => '#4f46e5',
        'icon'  => 'fa-solid fa-address-book',
        'home'  => 'crm/index.php',
        'match' => '/crm/',
        'items' => [
            ['label' => 'Dashboard',  'icon' => 'fa-solid fa-gauge',        'url' => 'crm/index.php',     'match' => 'crm/index.php'],
            ['label' => 'Customers',  'icon' => 'fa-solid fa-address-book', 'url' => 'crm/customers.php', 'match' => 'crm/customer'],
        ],
    ],
    'finance' => [
        'label' => 'Finance',
        'color' => '#ca8a04',
        'icon'  => 'fa-solid fa-sack-dollar',
        'home'  => 'accounting/index.php',
        'match' => '/accounting/',
        'items' => [
            ['label' => 'Overview',            'icon' => 'fa-solid fa-chart-pie',             'url' => 'accounting/index.php',            'match' => 'accounting/index.php'],
            ['label' => 'Accounts Receivable', 'icon' => 'fa-solid fa-hand-holding-dollar',   'url' => 'accounting/receivables.php',      'match' => ['accounting/receivable', 'accounting/invoice', 'accounting/payments']],
            ['label' => 'Accounts Payable',    'icon' => 'fa-solid fa-file-invoice',          'url' => 'accounting/payables.php',         'match' => ['accounting/payable', 'accounting/purchase_invoice']],
            ['label' => 'General Ledger',      'icon' => 'fa-solid fa-book',                  'url' => 'accounting/general_ledger.php',   'match' => ['accounting/general_ledger', 'accounting/ledger_account', 'accounting/journal']],
            ['label' => 'Bank & Cash',         'icon' => 'fa-solid fa-building-columns',      'url' => 'accounting/bank_cash.php',        'match' => ['accounting/bank_']],
            ['label' => 'Expenses',            'icon' => 'fa-solid fa-receipt',               'url' => 'accounting/expenses.php',         'match' => 'accounting/expense'],
            ['label' => 'Tax & Compliance',    'icon' => 'fa-solid fa-scale-balanced',        'url' => 'accounting/tax_compliance.php',   'match' => 'accounting/tax_'],
            ['label' => 'Financial Reports',   'icon' => 'fa-solid fa-chart-column',          'url' => 'accounting/reports.php',          'match' => ['accounting/report', 'accounting/statement', 'reports/financial']],
            ['label' => 'Budget & Planning',   'icon' => 'fa-solid fa-bullseye',              'url' => 'accounting/budgets.php',          'match' => 'accounting/budget'],
            ['label' => 'Configuration',       'icon' => 'fa-solid fa-gear',                  'url' => 'accounting/configuration.php',    'match' => ['accounting/configuration', 'accounting/cost_center']],
        ],
    ],
];

// Admin-only section, reached via the topbar user menu rather than the
// module grid (it isn't a business module).
$ADMIN_ITEMS = [
    'label' => 'Administration',
    'icon'  => 'fa-solid fa-gear',
    'match' => ['/users/', '/print_formats/'],
    'items' => [
        ['label' => 'Users',          'icon' => 'fa-solid fa-users-gear', 'url' => 'users/users.php',         'match' => 'users/user'],
        ['label' => 'Settings',       'icon' => 'fa-solid fa-gear',       'url' => 'users/settings.php',      'match' => 'users/settings'],
        ['label' => 'Print Formats',  'icon' => 'fa-solid fa-palette',    'url' => 'print_formats/index.php', 'match' => 'print_formats/'],
    ],
];

/** Returns the module key the given path belongs to, or null. */
function current_module_key(string $path): ?string
{
    global $MODULES;
    foreach ($MODULES as $key => $mod) {
        if (str_contains($path, $mod['match'])) {
            return $key;
        }
    }
    return null;
}
