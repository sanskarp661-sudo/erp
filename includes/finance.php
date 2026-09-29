<?php
/**
 * Finance module helpers: schema guard, system accounts, the derived
 * General Ledger, balances, number formatting and the shared page
 * widgets (page header, KPI cards, charts, tabs, pagination).
 *
 * The General Ledger is not a stored table. fin_gl_sql() builds one
 * UNION of postings read from the documents themselves:
 *   - ledger opening balances
 *   - sales invoices        Dr Receivable  / Cr Sales + Output GST
 *   - customer payments     Dr Bank|Cash   / Cr Receivable
 *   - purchase invoices     Dr Purchases + Input GST / Cr Payable
 *   - vendor payments       Dr Payable     / Cr Bank|Cash
 *   - approved expenses     Dr Expense ledger + Input GST / Cr Bank|Cash
 *   - submitted journals    their own lines
 * so every existing invoice and payment shows up without back-filling.
 */

/** True once migration 030 has been run on this database. */
function fin_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)db()->query("SHOW TABLES LIKE 'fin_journal_entries'")->fetchColumn()
                && (bool)db()->query("SHOW COLUMNS FROM ledger_accounts LIKE 'system_key'")->fetchColumn()
                && (bool)db()->query("SHOW COLUMNS FROM expenses LIKE 'expense_no'")->fetchColumn();
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Call at the top of every Finance page (after require_login()). If the
 * migration hasn't been run it renders a clear notice and stops, rather
 * than letting the page fail on a missing table.
 */
function fin_require_schema(): void
{
    // Hosts hide PHP errors behind a bare 500. On Finance pages, show admins the
    // reason instead so problems can be reported; everyone else sees a short note.
    set_exception_handler(function (Throwable $e) {
        error_log('Finance: ' . $e);
        if (!headers_sent()) http_response_code(500);
        $detail = can_edit_admin_section() || (defined('APP_DEBUG') && APP_DEBUG)
            ? '<pre class="small mb-0" style="white-space:pre-wrap">' . e($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') . '</pre>'
            : '<p class="mb-0">Please ask an administrator to check this page.</p>';
        echo '<div class="card p-4 m-3" style="max-width:820px"><h5 class="mb-2">This Finance page hit an error</h5>' . $detail . '</div>';
    });
    if (fin_ready()) {
        return;
    }
    global $page_title;
    $page_title = $page_title ?? 'Finance';
    require __DIR__ . '/header.php';
    echo '<div class="card p-4" style="max-width:720px">'
        . '<h5 class="mb-2"><i class="fa-solid fa-database text-warning"></i> Finance needs a database update</h5>'
        . '<p class="mb-2">The new Finance screens need migration <code>database/migrations/030_finance_module.sql</code>, which hasn\'t been run on this database yet.</p>'
        . '<ol class="mb-0"><li>Open <strong>phpMyAdmin</strong> in hPanel and select the ERP database.</li>'
        . '<li>Go to <strong>Import</strong> and upload <code>030_finance_module.sql</code>.</li>'
        . '<li>Reload this page.</li></ol></div>';
    require __DIR__ . '/footer.php';
    exit;
}

/** Cached "does this column exist" check, for code outside Finance that must work before and after migration 030. */
function db_has_column(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        try {
            $stmt = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$table, $column]);
            $cache[$key] = (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/** SQL fragment that hides Chart of Accounts groups from tax / charge head pickers. */
function ledger_heads_filter(string $alias = ''): string
{
    return db_has_column('ledger_accounts', 'is_group') ? ' AND ' . ($alias ? $alias . '.' : '') . 'is_group = 0' : '';
}

/* ------------------------------------------------------------------ */
/* Accounts                                                            */
/* ------------------------------------------------------------------ */

/** id of a system ledger (bank, cash, receivable, payable, sales, ...). */
function fin_account_id(string $systemKey): int
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (db()->query('SELECT id, system_key FROM ledger_accounts WHERE system_key IS NOT NULL') as $r) {
            $map[$r['system_key']] = (int)$r['id'];
        }
    }
    return $map[$systemKey] ?? 0;
}

/** All ledger accounts keyed by id. */
function fin_accounts(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = [];
        foreach (db()->query('SELECT * FROM ledger_accounts ORDER BY COALESCE(account_code, \'zzzz\'), name') as $r) {
            $rows[(int)$r['id']] = $r;
        }
    }
    return $rows;
}

/** Active, non-group ledgers for pickers, optionally filtered. */
function fin_ledger_options(?callable $filter = null): array
{
    $out = [];
    foreach (fin_accounts() as $id => $a) {
        if ($a['is_group'] || $a['status'] !== 'active') continue;
        if ($filter && !$filter($a)) continue;
        $out[$id] = $a;
    }
    return $out;
}

function fin_account_label(array $a): string
{
    return ($a['account_code'] ? $a['account_code'] . ' · ' : '') . $a['name'];
}

/** Bank / cash ledgers, the ones money can be paid from or into. */
function fin_money_accounts(): array
{
    return fin_ledger_options(fn($a) => $a['is_bank'] || $a['is_cash'] || in_array($a['account_type'], ['bank', 'cash'], true));
}

function fin_account_types(): array
{
    return [
        'bank' => 'Bank', 'cash' => 'Cash', 'receivable' => 'Receivable', 'payable' => 'Payable',
        'current_asset' => 'Current Asset', 'fixed_asset' => 'Fixed Asset', 'stock' => 'Stock',
        'current_liability' => 'Current Liability', 'loan' => 'Loan', 'tax' => 'Tax', 'equity' => 'Equity',
        'income' => 'Income', 'cost_of_goods_sold' => 'Cost of Goods Sold', 'expense' => 'Expense', 'other' => 'Other / Charges',
    ];
}

function fin_root_types(): array
{
    return ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];
}

/** Bank or cash ledger a payment method posts to. */
function fin_money_account_for(string $method): int
{
    return $method === 'cash' ? fin_account_id('cash') : fin_account_id('bank');
}

/* ------------------------------------------------------------------ */
/* Derived General Ledger                                              */
/* ------------------------------------------------------------------ */

/**
 * SQL for every GL posting, one row per account line, with columns:
 * entry_date, voucher_no, voucher_type, source_type, source_id, account_id,
 * debit, credit, party, description, cost_center_id, project.
 */
function fin_gl_sql(): string
{
    $ar = fin_account_id('receivable');
    $ap = fin_account_id('payable');
    $sales = fin_account_id('sales');
    $purch = fin_account_id('purchases');
    $outTax = fin_account_id('output_tax');
    $inTax = fin_account_id('input_tax');
    $bank = fin_account_id('bank');
    $cash = fin_account_id('cash');
    $exp = fin_account_id('expense');
    $payroll = fin_account_id('payroll');
    $moneyAcct = fn(string $col) => "CASE WHEN $col = 'cash' THEN $cash ELSE $bank END";

    $parts = [];
    // Opening balances (positive = the account's own nature).
    // Text columns carry an explicit collation here so the UNION works even when
    // the source tables were created with different collations (common on
    // hosts whose server default changed between installs).
    $c = fn(string $expr) => "CONVERT($expr USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    $parts[] = "SELECT COALESCE(la.opening_date, '2000-01-01') entry_date, {$c("'OPENING'")} voucher_no, {$c("'Opening Balance'")} voucher_type, {$c("'opening'")} source_type, la.id source_id, la.id account_id,
        CASE WHEN (la.account_nature = 'debit') = (la.opening_balance >= 0) THEN ABS(la.opening_balance) ELSE 0 END debit,
        CASE WHEN (la.account_nature = 'debit') = (la.opening_balance >= 0) THEN 0 ELSE ABS(la.opening_balance) END credit,
        {$c('NULL')} party, {$c("'Opening balance'")} description, la.cost_center_id, {$c('la.project')} project
        FROM ledger_accounts la WHERE la.opening_balance <> 0 AND la.is_group = 0";
    // ...offset against Opening Balance Equity so the trial balance always agrees.
    $openEq = fin_account_id('opening_equity');
    if ($openEq) {
        $parts[] = "SELECT COALESCE(la.opening_date, '2000-01-01'), 'OPENING', 'Opening Balance', 'opening', la.id, $openEq,
            CASE WHEN (la.account_nature = 'debit') = (la.opening_balance >= 0) THEN 0 ELSE ABS(la.opening_balance) END,
            CASE WHEN (la.account_nature = 'debit') = (la.opening_balance >= 0) THEN ABS(la.opening_balance) ELSE 0 END,
            NULL, CONCAT('Opening balance - ', la.name), NULL, NULL
            FROM ledger_accounts la WHERE la.opening_balance <> 0 AND la.is_group = 0 AND la.id <> $openEq";
    }

    $inv = "FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.status <> 'cancelled'";
    $parts[] = "SELECT i.invoice_date, i.invoice_no, 'Sales Invoice', 'invoice', i.id, $ar, i.total, 0, c.name, CONCAT('Sales Invoice - ', c.name), NULL, NULL $inv";
    $parts[] = "SELECT i.invoice_date, i.invoice_no, 'Sales Invoice', 'invoice', i.id, $sales, 0, i.total - i.tax, c.name, CONCAT('Sales Invoice - ', c.name), NULL, NULL $inv";
    $parts[] = "SELECT i.invoice_date, i.invoice_no, 'Sales Invoice', 'invoice', i.id, $outTax, 0, i.tax, c.name, CONCAT('Output tax - ', i.invoice_no), NULL, NULL $inv AND i.tax <> 0";

    $pay = "FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN customers c ON c.id = i.customer_id";
    $parts[] = "SELECT p.payment_date, CONCAT('RCPT-', LPAD(p.id, 5, '0')), 'Receipt', 'payment', p.id, CASE WHEN p.method = 'credit_note' THEN $sales ELSE {$moneyAcct('p.method')} END, p.amount, 0, c.name, CONCAT('Customer Payment - ', i.invoice_no), NULL, NULL $pay";
    $parts[] = "SELECT p.payment_date, CONCAT('RCPT-', LPAD(p.id, 5, '0')), 'Receipt', 'payment', p.id, $ar, 0, p.amount, c.name, CONCAT('Invoice Settlement - ', i.invoice_no), NULL, NULL $pay";

    $pi = "FROM purchase_invoices pi JOIN vendors v ON v.id = pi.vendor_id WHERE pi.status <> 'cancelled'";
    $parts[] = "SELECT pi.invoice_date, pi.pi_no, 'Purchase Invoice', 'purchase_invoice', pi.id, $purch, pi.total - pi.tax, 0, v.name, CONCAT('Purchase Invoice - ', v.name), NULL, NULL $pi";
    $parts[] = "SELECT pi.invoice_date, pi.pi_no, 'Purchase Invoice', 'purchase_invoice', pi.id, $inTax, pi.tax, 0, v.name, CONCAT('Input tax - ', pi.pi_no), NULL, NULL $pi AND pi.tax <> 0";
    $parts[] = "SELECT pi.invoice_date, pi.pi_no, 'Purchase Invoice', 'purchase_invoice', pi.id, $ap, 0, pi.total, v.name, CONCAT('Purchase Invoice - ', v.name), NULL, NULL $pi";

    $pp = "FROM purchase_payments pp JOIN purchase_invoices pi ON pi.id = pp.purchase_invoice_id JOIN vendors v ON v.id = pi.vendor_id";
    $parts[] = "SELECT pp.payment_date, CONCAT('PAY-', LPAD(pp.id, 5, '0')), 'Payment', 'purchase_payment', pp.id, $ap, pp.amount, 0, v.name, CONCAT('Payment to ', v.name), NULL, NULL $pp";
    $parts[] = "SELECT pp.payment_date, CONCAT('PAY-', LPAD(pp.id, 5, '0')), 'Payment', 'purchase_payment', pp.id, CASE WHEN pp.method = 'debit_note' THEN $purch ELSE {$moneyAcct('pp.method')} END, 0, pp.amount, v.name, CONCAT('Payment to ', v.name), NULL, NULL $pp";

    $ex = "FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.status = 'approved'";
    $payee = "COALESCE(v.name, e.payee, e.category)";
    $parts[] = "SELECT e.expense_date, COALESCE(e.expense_no, CONCAT('EXP-', LPAD(e.id, 6, '0'))), 'Expense', 'expense', e.id, COALESCE(e.account_id, CASE WHEN e.category = 'Payroll' THEN $payroll ELSE $exp END), e.amount - e.tax_amount, 0, $payee, COALESCE(NULLIF(e.description, ''), e.category), e.cost_center_id, e.project $ex";
    $parts[] = "SELECT e.expense_date, COALESCE(e.expense_no, CONCAT('EXP-', LPAD(e.id, 6, '0'))), 'Expense', 'expense', e.id, $inTax, e.tax_amount, 0, $payee, CONCAT('Input tax - ', COALESCE(e.expense_no, e.id)), e.cost_center_id, e.project $ex AND e.tax_amount <> 0";
    $parts[] = "SELECT e.expense_date, COALESCE(e.expense_no, CONCAT('EXP-', LPAD(e.id, 6, '0'))), 'Expense', 'expense', e.id, COALESCE(e.paid_from_account_id, {$moneyAcct('e.payment_method')}), 0, e.amount, $payee, COALESCE(NULLIF(e.description, ''), e.category), e.cost_center_id, e.project $ex";

    $parts[] = "SELECT j.posting_date, j.voucher_no, CASE j.voucher_type WHEN 'journal' THEN 'Journal Entry' WHEN 'bank_payment' THEN 'Bank Payment' WHEN 'bank_receipt' THEN 'Bank Receipt'
          WHEN 'cash_payment' THEN 'Cash Payment' WHEN 'cash_receipt' THEN 'Cash Receipt' ELSE 'Contra' END, 'journal', j.id, l.account_id, l.debit, l.credit, j.party_name,
          COALESCE(NULLIF(l.line_narration, ''), j.narration, ''), COALESCE(l.cost_center_id, j.cost_center_id), COALESCE(l.project, j.project)
        FROM fin_journal_lines l JOIN fin_journal_entries j ON j.id = l.journal_id WHERE j.status = 'submitted'";

    return implode("\nUNION ALL\n", $parts);
}

/** Per-account [debit, credit] totals, optionally between dates (inclusive). */
function fin_account_totals(?string $from = null, ?string $to = null): array
{
    $where = [];
    $params = [];
    if ($from) { $where[] = 'entry_date >= ?'; $params[] = $from; }
    if ($to) { $where[] = 'entry_date <= ?'; $params[] = $to; }
    $sql = 'SELECT account_id, SUM(debit) dr, SUM(credit) cr FROM (' . fin_gl_sql() . ') g'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY account_id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt as $r) {
        $out[(int)$r['account_id']] = ['dr' => (float)$r['dr'], 'cr' => (float)$r['cr']];
    }
    return $out;
}

/** Balance (debit - credit) per account id, groups rolled up from their children. */
function fin_balances(?string $to = null, ?string $from = null): array
{
    $totals = fin_account_totals($from, $to);
    $accounts = fin_accounts();
    $bal = [];
    foreach ($accounts as $id => $a) {
        $bal[$id] = isset($totals[$id]) ? $totals[$id]['dr'] - $totals[$id]['cr'] : 0.0;
    }
    // Roll leaf balances up the tree (guarding against cycles).
    $rolled = array_fill_keys(array_keys($accounts), 0.0);
    foreach ($accounts as $id => $a) {
        if ($a['is_group'] || !$bal[$id]) continue;
        $cur = $id;
        $seen = [];
        while ($cur && isset($accounts[$cur]) && !isset($seen[$cur])) {
            $seen[$cur] = true;
            $rolled[$cur] += $bal[$id];
            $cur = (int)$accounts[$cur]['parent_id'];
        }
    }
    return $rolled;
}

/** Sum of balances (debit - credit) for the given leaf account ids as of a date. */
function fin_balance_of(array $accountIds, ?string $to = null): float
{
    $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
    if (!$accountIds) return 0.0;
    $in = implode(',', $accountIds);
    $sql = 'SELECT COALESCE(SUM(debit - credit), 0) FROM (' . fin_gl_sql() . ") g WHERE account_id IN ($in)" . ($to ? ' AND entry_date <= ?' : '');
    $stmt = db()->prepare($sql);
    $stmt->execute($to ? [$to] : []);
    return (float)$stmt->fetchColumn();
}

/** Leaf ids under root types (e.g. ['income']). */
function fin_leaf_ids_by_root(array $rootTypes): array
{
    return array_keys(array_filter(fin_accounts(), fn($a) => !$a['is_group'] && in_array($a['root_type'], $rootTypes, true)));
}

/** Monthly [debit, credit] sums for the given accounts between two dates, keyed Y-m. */
function fin_monthly_totals(array $accountIds, string $from, string $to): array
{
    $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
    if (!$accountIds) return [];
    $in = implode(',', $accountIds);
    $stmt = db()->prepare("SELECT DATE_FORMAT(entry_date, '%Y-%m') ym, SUM(debit) dr, SUM(credit) cr FROM (" . fin_gl_sql() . ") g
        WHERE account_id IN ($in) AND entry_date BETWEEN ? AND ? AND source_type <> 'opening' GROUP BY ym");
    $stmt->execute([$from, $to]);
    $out = [];
    foreach ($stmt as $r) {
        $out[$r['ym']] = ['dr' => (float)$r['dr'], 'cr' => (float)$r['cr']];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Dates / fiscal year                                                 */
/* ------------------------------------------------------------------ */

/** Start date (Y-m-d) of the fiscal year containing $date. */
function fin_fy_start(?string $date = null): string
{
    $m = max(1, min(12, (int)setting('fin_fy_start_month', '4')));
    $ts = strtotime($date ?: today());
    $y = (int)date('Y', $ts);
    if ((int)date('n', $ts) < $m) $y--;
    return sprintf('%04d-%02d-01', $y, $m);
}

function fin_fy_end(string $fyStart): string
{
    return date('Y-m-d', strtotime($fyStart . ' +1 year -1 day'));
}

function fin_fy_label(string $fyStart): string
{
    $y = (int)substr($fyStart, 0, 4);
    return $y . '-' . substr((string)($y + 1), 2);
}

/** Last $n months as ['2026-04' => 'Apr 2026', ...] ending with the current month. */
function fin_last_months(int $n, string $fmt = 'M Y'): array
{
    $out = [];
    $base = strtotime(date('Y-m-01'));
    for ($i = $n - 1; $i >= 0; $i--) {
        $ts = strtotime("-$i month", $base);
        $out[date('Y-m', $ts)] = date($fmt, $ts);
    }
    return $out;
}

function fin_date($d): string
{
    return $d ? date('d M Y', strtotime($d)) : '—';
}

/* ------------------------------------------------------------------ */
/* Numbers                                                             */
/* ------------------------------------------------------------------ */

/** Indian digit grouping (12,34,567.00) when the currency is INR, else standard. */
function fin_num($amount, int $decimals = 2): string
{
    $amount = (float)$amount;
    $neg = $amount < 0;
    $s = number_format(abs($amount), $decimals, '.', '');
    if (strtoupper((string)setting('currency_code', 'INR')) === 'INR') {
        [$int, $dec] = array_pad(explode('.', $s), 2, '');
        if (strlen($int) > 3) {
            $last3 = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest . ',' . $last3;
        }
        $s = $int . ($decimals ? '.' . $dec : '');
    } else {
        $s = number_format(abs($amount), $decimals);
    }
    return ($neg ? '-' : '') . $s;
}

/** Currency amount, e.g. ₹ 4,85,230. */
function fin_money($amount, int $decimals = 0): string
{
    $amount = (float)$amount;
    return ($amount < 0 ? '-' : '') . setting('currency_symbol', '₹') . ' ' . fin_num(abs($amount), $decimals);
}

/** Table amount; negatives shown as red (1,234.00) like the ledger screens. */
function fin_amt($amount, bool $parens = true): string
{
    $amount = round((float)$amount, 2);
    if ($amount < 0 && $parens) {
        return '<span class="text-danger">(' . fin_num(abs($amount)) . ')</span>';
    }
    return fin_num($amount);
}

/** % change from $prev to $now; null when there is no base to compare with. */
function fin_pct_change(float $now, float $prev): ?float
{
    if (abs($prev) < 0.005) {
        return abs($now) < 0.005 ? 0.0 : null;
    }
    return round(($now - $prev) / abs($prev) * 100, 1);
}

/* ------------------------------------------------------------------ */
/* Document numbering                                                  */
/* ------------------------------------------------------------------ */

/** Next number like JV-2026-0082 for the given prefix setting within a table/column. */
function fin_next_no(string $prefixKey, string $defaultPrefix, string $table, string $column): string
{
    $prefix = trim((string)setting('fin_prefix_' . $prefixKey, $defaultPrefix)) ?: $defaultPrefix;
    $pad = max(3, min(8, (int)setting('fin_number_padding', '4')));
    $stem = $prefix . '-' . date('Y') . '-';
    $stmt = db()->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY LENGTH($column) DESC, $column DESC LIMIT 1");
    $stmt->execute([$stem . '%']);
    $last = $stmt->fetchColumn();
    $num = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $num = (int)$m[1] + 1;
    }
    return $stem . str_pad((string)$num, $pad, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------ */
/* Page widgets                                                        */
/* ------------------------------------------------------------------ */

/** Chart.js + the finance chart initialiser; add to $extra_js on pages with charts. */
function fin_chart_js(): array
{
    return ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', asset_url('assets/js/finance.js')];
}

/** Big serif page title with subtitle, and optional right-hand actions. */
function fin_page_head(string $title, string $subtitle, string $rightHtml = ''): void
{
    echo '<div class="fin-head"><div><h1 class="fin-title">' . e($title) . '</h1><p class="fin-subtitle">' . e($subtitle) . '</p></div>';
    if ($rightHtml !== '') {
        echo '<div class="fin-head-actions">' . $rightHtml . '</div>';
    }
    echo '</div>';
}

function fin_date_chip(?string $text = null): string
{
    return '<span class="fin-chip"><i class="fa-regular fa-calendar"></i> ' . e($text ?? date('l, j F Y')) . '</span>';
}

/**
 * KPI card. $tone: blue|red|green|purple|orange|teal. $trend is a % change
 * (null hides it); $goodWhenUp flips the colour for costs.
 */
function fin_kpi(string $icon, string $tone, string $label, string $value, ?float $trend = null, string $trendLabel = '', ?string $sub = null, bool $goodWhenUp = true, string $cardTone = ''): string
{
    $html = '<div class="fin-kpi' . ($cardTone ? ' fin-kpi-' . $cardTone : '') . '"><div class="fin-kpi-icon tone-' . $tone . '"><i class="' . e($icon) . '"></i></div><div class="fin-kpi-body">'
        . '<div class="fin-kpi-label">' . e($label) . '</div><div class="fin-kpi-value">' . $value . '</div>';
    if ($trend !== null) {
        $up = $trend >= 0;
        $good = $up === $goodWhenUp;
        $html .= '<div class="fin-kpi-trend"><span class="' . ($good ? 'text-success' : 'text-danger') . '"><i class="fa-solid fa-arrow-' . ($up ? 'up' : 'down') . '"></i> '
            . e(rtrim(rtrim(number_format(abs($trend), 1), '0'), '.')) . '%</span> ' . e($trendLabel) . '</div>';
    } elseif ($sub !== null) {
        $html .= '<div class="fin-kpi-trend">' . $sub . '</div>';
    }
    return $html . '</div></div>';
}

/** Render a row of KPI cards. */
function fin_kpi_row(array $cards): void
{
    echo '<div class="row g-3 mb-3">';
    foreach ($cards as $c) {
        echo '<div class="col-sm-6 col-xl-3">' . $c . '</div>';
    }
    echo '</div>';
}

/** Canvas the finance.js initialiser turns into a Chart.js chart. */
function fin_chart(string $id, array $config, int $height = 260): string
{
    static $globals = false;
    $prefix = '';
    if (!$globals) {
        $globals = true;
        $prefix = '<script>window.FIN_CURRENCY=' . json_encode(strtoupper((string)setting('currency_code', 'INR'))) . ';window.FIN_SYMBOL=' . json_encode(setting('currency_symbol', '₹') . ' ') . ';</script>';
    }
    return $prefix . '<div class="fin-chart-wrap" style="height:' . $height . 'px"><canvas id="' . e($id) . '" data-fin-chart="' . e(json_encode($config)) . '"></canvas></div>';
}

/** Soft coloured status pill. $tone: success|danger|warning|info|primary|secondary|purple. */
function fin_pill(string $text, string $tone = 'secondary'): string
{
    return '<span class="fin-pill fin-pill-' . e($tone) . '">' . e($text) . '</span>';
}

/** Underline tabs that link (?tab=key), keeping other query params. */
function fin_tabs(array $tabs, string $active, string $param = 'tab'): string
{
    $html = '<ul class="fin-tabs">';
    foreach ($tabs as $key => $label) {
        $q = array_merge($_GET, [$param => $key]);
        unset($q['page']);
        $html .= '<li><a class="' . ($key === $active ? 'active' : '') . '" href="?' . e(http_build_query($q)) . '">' . e($label) . '</a></li>';
    }
    return $html . '</ul>';
}

/** [page, perPage, offset] from the query string. */
function fin_page(int $perPage = 10): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    return [$page, $perPage, ($page - 1) * $perPage];
}

/** "Showing 1 to 5 of 28 invoices" + numbered pager. */
function fin_pagination(int $total, int $page, int $perPage, string $noun = 'entries'): string
{
    $pages = max(1, (int)ceil($total / $perPage));
    $from = $total ? ($page - 1) * $perPage + 1 : 0;
    $to = min($total, $page * $perPage);
    $link = function (int $p) {
        return '?' . e(http_build_query(array_merge($_GET, ['page' => $p])));
    };
    $html = '<div class="fin-pager"><div class="text-muted small">Showing ' . $from . ' to ' . $to . ' of ' . $total . ' ' . e($noun) . '</div>';
    if ($pages > 1) {
        $html .= '<nav><ul class="pagination pagination-sm mb-0">';
        $html .= '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . $link(max(1, $page - 1)) . '"><i class="fa-solid fa-chevron-left"></i></a></li>';
        $shown = [];
        foreach ([1, 2, 3, 4, 5, $page - 1, $page, $page + 1, $pages] as $p) {
            if ($p >= 1 && $p <= $pages) $shown[$p] = true;
        }
        ksort($shown);
        $prev = 0;
        foreach (array_keys($shown) as $p) {
            if ($prev && $p > $prev + 1) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            $html .= '<li class="page-item ' . ($p === $page ? 'active' : '') . '"><a class="page-link" href="' . $link($p) . '">' . $p . '</a></li>';
            $prev = $p;
        }
        $html .= '<li class="page-item ' . ($page >= $pages ? 'disabled' : '') . '"><a class="page-link" href="' . $link(min($pages, $page + 1)) . '"><i class="fa-solid fa-chevron-right"></i></a></li>';
        $html .= '</ul></nav>';
    }
    return $html . '</div>';
}

/** Quick action tile. */
function fin_action_tile(string $href, string $icon, string $tone, string $label): string
{
    return '<a class="fin-tile" href="' . e($href) . '"><span class="fin-kpi-icon tone-' . $tone . '"><i class="' . e($icon) . '"></i></span><span>' . e($label) . '</span></a>';
}

/** Stream rows as a CSV download and exit. */
function fin_csv(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

/** Aging bucket label for a number of days past due. */
function fin_aging_buckets(): array
{
    return ['current' => 'Current (0–30 days)', '31_60' => '31–60 days', '61_90' => '61–90 days', '91_120' => '91–120 days', '120_plus' => '> 120 days'];
}

/**
 * Receivables / payables aging: outstanding grouped by days past the due
 * date (invoices not yet due count as current).
 */
function fin_aging(string $kind): array
{
    [$table, $dateCol] = $kind === 'payable' ? ['purchase_invoices', 'invoice_date'] : ['invoices', 'invoice_date'];
    $rows = db()->query("SELECT DATEDIFF(CURDATE(), COALESCE(due_date, $dateCol)) days, total - amount_paid bal
        FROM $table WHERE status IN ('unpaid','partially_paid','overdue') AND total - amount_paid > 0.005")->fetchAll();
    $b = array_fill_keys(array_keys(fin_aging_buckets()), 0.0);
    foreach ($rows as $r) {
        $d = (int)$r['days'];
        $k = $d <= 30 ? 'current' : ($d <= 60 ? '31_60' : ($d <= 90 ? '61_90' : ($d <= 120 ? '91_120' : '120_plus')));
        $b[$k] += (float)$r['bal'];
    }
    return $b;
}

/** Common palette shared with finance.js. */
function fin_palette(): array
{
    return ['#2563eb', '#a78bfa', '#22c55e', '#f59e0b', '#60a5fa', '#ec4899', '#14b8a6', '#cbd5e1'];
}

/* ------------------------------------------------------------------ */
/* Journal vouchers                                                    */
/* ------------------------------------------------------------------ */

function fin_voucher_types(): array
{
    return [
        'journal' => 'Journal Entry', 'bank_payment' => 'Bank Payment', 'bank_receipt' => 'Bank Receipt',
        'cash_payment' => 'Cash Payment', 'cash_receipt' => 'Cash Receipt', 'contra' => 'Contra (Bank Transfer)',
    ];
}

/** 'payment' (money out), 'receipt' (money in), or 'journal' (balanced lines). */
function fin_voucher_flow(string $type): string
{
    if (in_array($type, ['bank_payment', 'cash_payment', 'contra'], true)) return 'payment';
    if (in_array($type, ['bank_receipt', 'cash_receipt'], true)) return 'receipt';
    return 'journal';
}

function fin_voucher_prefix_key(string $type): array
{
    $defaults = ['journal' => 'JV', 'bank_payment' => 'BP', 'bank_receipt' => 'BR', 'cash_payment' => 'CP', 'cash_receipt' => 'CR', 'contra' => 'CT'];
    return [$type, $defaults[$type] ?? 'JV'];
}

/* ------------------------------------------------------------------ */
/* Expenses                                                            */
/* ------------------------------------------------------------------ */

function fin_expense_categories(): array
{
    $defaults = ['Office Supplies', 'IT & Software', 'Office Maintenance', 'Utilities', 'Rent', 'Travel & Food', 'Travel & Conveyance',
        'Bank Charges', 'Office Equipment', 'Marketing', 'Professional Fees', 'Repairs & Maintenance', 'Payroll', 'Miscellaneous'];
    $used = db()->query("SELECT DISTINCT category FROM expenses WHERE category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    $all = array_unique(array_merge($defaults, $used));
    sort($all);
    return $all;
}

function fin_payment_modes(): array
{
    return ['bank_transfer' => 'Bank Transfer', 'upi' => 'UPI', 'cash' => 'Cash', 'card' => 'Card', 'cheque' => 'Cheque', 'auto_debit' => 'Auto Debit', 'other' => 'Other'];
}

function fin_expense_no_display(array $e): string
{
    return $e['expense_no'] ?: 'EXP-' . str_pad((string)$e['id'], 6, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------ */
/* Tax & Compliance                                                    */
/* ------------------------------------------------------------------ */

function fin_tax_categories(): array
{
    return ['gst' => 'GST', 'tds' => 'TDS', 'pf' => 'PF', 'esi' => 'ESI', 'professional_tax' => 'Professional Tax', 'income_tax' => 'Income Tax', 'other' => 'Other'];
}

/** Return type => [category, due day of the following month, or 'q' for quarterly (end of the following month)]. */
function fin_tax_return_types(): array
{
    return [
        'GSTR-1' => ['gst', 11], 'GSTR-3B' => ['gst', 20], 'GST Payment' => ['gst', 20],
        'TDS Payment' => ['tds', 7], 'TDS Return 24Q' => ['tds', 'q'], 'TDS Return 26Q' => ['tds', 'q'],
        'PF Return' => ['pf', 15], 'ESI Return' => ['esi', 15], 'Professional Tax' => ['professional_tax', 20],
        'Advance Tax' => ['income_tax', 15],
    ];
}

/** Statutory due date for a return covering $periodMonth (Y-m-01). */
function fin_tax_due_date(string $returnType, string $periodMonth): string
{
    $rule = fin_tax_return_types()[$returnType][1] ?? 20;
    $next = strtotime(date('Y-m-01', strtotime($periodMonth)) . ' +1 month');
    if ($rule === 'q') return date('Y-m-t', $next);
    return date('Y-m-', $next) . str_pad((string)$rule, 2, '0', STR_PAD_LEFT);
}

/** GST from the books for one month: output (sales), input (purchases + expenses), net payable. */
function fin_gst_from_books(string $periodMonth): array
{
    $from = date('Y-m-01', strtotime($periodMonth));
    $to = date('Y-m-t', strtotime($from));
    $q = function (string $sql) use ($from, $to) {
        $stmt = db()->prepare($sql);
        $stmt->execute([$from, $to]);
        return (float)$stmt->fetchColumn();
    };
    $output = $q("SELECT COALESCE(SUM(tax),0) FROM invoices WHERE status <> 'cancelled' AND invoice_date BETWEEN ? AND ?");
    $input = $q("SELECT COALESCE(SUM(tax),0) FROM purchase_invoices WHERE status <> 'cancelled' AND invoice_date BETWEEN ? AND ?")
        + $q("SELECT COALESCE(SUM(tax_amount),0) FROM expenses WHERE status = 'approved' AND expense_date BETWEEN ? AND ?");
    return ['output' => $output, 'input' => $input, 'net' => max(0, $output - $input)];
}

/* ------------------------------------------------------------------ */
/* Budgets                                                             */
/* ------------------------------------------------------------------ */

/** Budgets of a fiscal year with their 12 monthly amounts ('months' => [1 => amt, ...]). */
function fin_budgets(string $fyStart): array
{
    $stmt = db()->prepare('SELECT b.*, d.name department_name, cc.name cc_name FROM fin_budgets b LEFT JOIN departments d ON d.id = b.department_id
        LEFT JOIN fin_cost_centers cc ON cc.id = b.cost_center_id WHERE b.fiscal_year_start = ? ORDER BY b.name');
    $stmt->execute([$fyStart]);
    $budgets = [];
    foreach ($stmt as $b) {
        $b['months'] = array_fill(1, 12, round((float)$b['amount'] / 12, 2));
        $budgets[(int)$b['id']] = $b;
    }
    if ($budgets) {
        $ids = implode(',', array_keys($budgets));
        foreach (db()->query("SELECT budget_id, month_index, amount FROM fin_budget_months WHERE budget_id IN ($ids)") as $m) {
            if ($budgets[(int)$m['budget_id']]['distribution'] === 'custom') {
                $budgets[(int)$m['budget_id']]['months'][(int)$m['month_index']] = (float)$m['amount'];
            }
        }
    }
    return $budgets;
}

/** Approved expenses matching a budget's department / category / cost center, per FY month index (1-12). */
function fin_budget_actuals(array $budget, string $fyStart): array
{
    $where = ["status = 'approved'", 'expense_date BETWEEN ? AND ?'];
    $params = [$fyStart, fin_fy_end($fyStart)];
    if ($budget['category'] !== null && $budget['category'] !== '') { $where[] = 'category = ?'; $params[] = $budget['category']; }
    if ($budget['department_id']) { $where[] = 'department_id = ?'; $params[] = $budget['department_id']; }
    if ($budget['cost_center_id']) { $where[] = 'cost_center_id = ?'; $params[] = $budget['cost_center_id']; }
    $stmt = db()->prepare("SELECT PERIOD_DIFF(DATE_FORMAT(expense_date, '%Y%m'), DATE_FORMAT(?, '%Y%m')) + 1 mi, SUM(amount) v FROM expenses WHERE " . implode(' AND ', $where) . ' GROUP BY mi');
    $stmt->execute(array_merge([$fyStart], $params));
    $out = array_fill(1, 12, 0.0);
    foreach ($stmt as $r) {
        if ($r['mi'] >= 1 && $r['mi'] <= 12) $out[(int)$r['mi']] = (float)$r['v'];
    }
    return $out;
}

/** Number of FY months elapsed up to today (1-12), or 12 for a past year, 0 for a future one. */
function fin_fy_months_elapsed(string $fyStart): int
{
    $today = today();
    if ($today < $fyStart) return 0;
    if ($today > fin_fy_end($fyStart)) return 12;
    return (int)((date('Y', strtotime($today)) - date('Y', strtotime($fyStart))) * 12 + date('n', strtotime($today)) - date('n', strtotime($fyStart))) + 1;
}
