-- Migration 030: Finance module (10X YOU Finance UI).
--
-- Turns Finance into a proper accounting module: a hierarchical Chart of
-- Accounts (groups + ledgers with codes, nature, opening balances, bank /
-- cash flags), journal vouchers (journal, bank/cash payment and receipt,
-- contra), bank reconciliation, richer expenses (voucher no, payee,
-- expense ledger, cost center, approval), GST/TDS/PF/ESI filings, budgets
-- and cost centers.
--
-- The General Ledger is derived: sales/purchase invoices, their payments,
-- approved expenses and submitted journals are all read straight from
-- their own tables and posted to the system accounts seeded below, so
-- nothing needs back-filling.
--
-- Run this BEFORE uploading / pulling the new Finance pages. Until it is
-- run, the Finance pages show a notice asking for it instead of an error.
--
-- Safe to re-run: every CREATE / ADD COLUMN / INSERT is idempotent.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Cost centers
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fin_cost_centers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) DEFAULT NULL,
  name VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(255) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO fin_cost_centers (code, name, description) VALUES
('HO', 'Head Office', 'Default cost center');

-- ---------------------------------------------------------------------
-- Chart of Accounts: ledger_accounts becomes a tree of groups + ledgers.
-- account_type keeps its old meaning for documents ("tax" heads count as
-- tax on sales / purchase orders); the new values classify the rest.
-- ---------------------------------------------------------------------
ALTER TABLE ledger_accounts MODIFY account_type ENUM('tax','income','expense','other','bank','cash','receivable','payable','current_asset','fixed_asset','stock','current_liability','loan','equity','cost_of_goods_sold') NOT NULL DEFAULT 'other';
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS account_code VARCHAR(20) DEFAULT NULL AFTER id;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS parent_id INT UNSIGNED DEFAULT NULL AFTER name;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS is_group TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_id;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS root_type ENUM('asset','liability','equity','income','expense') DEFAULT NULL AFTER is_group;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS account_nature ENUM('debit','credit') NOT NULL DEFAULT 'debit' AFTER account_type;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS statement_category VARCHAR(40) DEFAULT NULL AFTER account_nature;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS description VARCHAR(255) DEFAULT NULL AFTER statement_category;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS currency VARCHAR(3) NOT NULL DEFAULT 'INR' AFTER description;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER currency;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS opening_date DATE DEFAULT NULL AFTER opening_balance;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS cost_center_id INT UNSIGNED DEFAULT NULL AFTER opening_date;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS project VARCHAR(100) DEFAULT NULL AFTER cost_center_id;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS is_bank TINYINT(1) NOT NULL DEFAULT 0 AFTER project;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS is_cash TINYINT(1) NOT NULL DEFAULT 0 AFTER is_bank;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS bank_account_no VARCHAR(40) DEFAULT NULL AFTER is_cash;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS bank_ifsc VARCHAR(11) DEFAULT NULL AFTER bank_account_no;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS tax_applicability VARCHAR(20) DEFAULT NULL AFTER bank_ifsc;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS default_tax_template_id INT UNSIGNED DEFAULT NULL AFTER tax_applicability;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS tags VARCHAR(255) DEFAULT NULL AFTER default_tax_template_id;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS system_key VARCHAR(40) DEFAULT NULL AFTER tags;
ALTER TABLE ledger_accounts ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE ledger_accounts ADD UNIQUE INDEX IF NOT EXISTS uniq_ledger_code (account_code);
ALTER TABLE ledger_accounts ADD UNIQUE INDEX IF NOT EXISTS uniq_ledger_system_key (system_key);
ALTER TABLE ledger_accounts ADD INDEX IF NOT EXISTS idx_ledger_parent (parent_id);

-- Standard groups and the system ledgers the derived General Ledger posts
-- to. Ledger opening balances are offset against Opening Balance Equity so
-- the books always balance. ON DUPLICATE KEY keeps an account the user already created with the
-- same name, only tagging it with its system key.
INSERT INTO ledger_accounts (account_code, name, is_group, root_type, account_type, account_nature, statement_category, system_key) VALUES
('1000', 'Assets',                  1, 'asset',     'current_asset',     'debit',  'balance_sheet', 'grp_assets'),
('1100', 'Current Assets',          1, 'asset',     'current_asset',     'debit',  'balance_sheet', 'grp_current_assets'),
('1110', 'Bank Accounts',           1, 'asset',     'bank',              'debit',  'balance_sheet', 'grp_bank'),
('1200', 'Non-Current Assets',      1, 'asset',     'fixed_asset',       'debit',  'balance_sheet', 'grp_fixed_assets'),
('2000', 'Liabilities',             1, 'liability', 'current_liability', 'credit', 'balance_sheet', 'grp_liabilities'),
('2100', 'Current Liabilities',     1, 'liability', 'current_liability', 'credit', 'balance_sheet', 'grp_current_liabilities'),
('2120', 'Duties & Taxes',          1, 'liability', 'tax',               'credit', 'balance_sheet', 'grp_taxes'),
('2200', 'Non-Current Liabilities', 1, 'liability', 'loan',              'credit', 'balance_sheet', 'grp_loans'),
('3000', 'Equity',                  1, 'equity',    'equity',            'credit', 'balance_sheet', 'grp_equity'),
('4000', 'Income',                  1, 'income',    'income',            'credit', 'profit_loss',   'grp_income'),
('5000', 'Expenses',                1, 'expense',   'expense',           'debit',  'profit_loss',   'grp_expenses'),
('5900', 'Indirect Expenses',       1, 'expense',   'expense',           'debit',  'profit_loss',   'grp_indirect_expenses')
ON DUPLICATE KEY UPDATE
  account_nature     = IF(ledger_accounts.root_type IS NULL, VALUES(account_nature), ledger_accounts.account_nature),
  statement_category = COALESCE(ledger_accounts.statement_category, VALUES(statement_category)),
  root_type          = COALESCE(ledger_accounts.root_type, VALUES(root_type)),
  account_code       = COALESCE(ledger_accounts.account_code, VALUES(account_code)),
  system_key         = COALESCE(ledger_accounts.system_key, VALUES(system_key));

INSERT INTO ledger_accounts (account_code, name, is_group, root_type, account_type, account_nature, statement_category, is_bank, is_cash, system_key) VALUES
('1110-01', 'Primary Bank Account', 0, 'asset',     'bank',               'debit',  'balance_sheet', 1, 0, 'bank'),
('1120',    'Cash in Hand',         0, 'asset',     'cash',               'debit',  'balance_sheet', 0, 1, 'cash'),
('1130',    'Accounts Receivable',  0, 'asset',     'receivable',         'debit',  'balance_sheet', 0, 0, 'receivable'),
('1140',    'Input GST',            0, 'asset',     'tax',                'debit',  'balance_sheet', 0, 0, 'input_tax'),
('2110',    'Accounts Payable',     0, 'liability', 'payable',            'credit', 'balance_sheet', 0, 0, 'payable'),
('2121',    'Output GST Payable',   0, 'liability', 'tax',                'credit', 'balance_sheet', 0, 0, 'output_tax'),
('3100',    'Owner''s Equity',      0, 'equity',    'equity',             'credit', 'balance_sheet', 0, 0, 'equity'),
('3200',    'Opening Balance Equity', 0, 'equity',  'equity',             'credit', 'balance_sheet', 0, 0, 'opening_equity'),
('4100',    'Sales Revenue',        0, 'income',    'income',             'credit', 'profit_loss',   0, 0, 'sales'),
('5100',    'Operating Expenses',   0, 'expense',   'expense',            'debit',  'profit_loss',   0, 0, 'expense'),
('5200',    'Purchases',            0, 'expense',   'cost_of_goods_sold', 'debit',  'profit_loss',   0, 0, 'purchases'),
('5300',    'Salaries & Wages',     0, 'expense',   'expense',            'debit',  'profit_loss',   0, 0, 'payroll')
ON DUPLICATE KEY UPDATE
  account_nature     = IF(ledger_accounts.root_type IS NULL, VALUES(account_nature), ledger_accounts.account_nature),
  statement_category = COALESCE(ledger_accounts.statement_category, VALUES(statement_category)),
  root_type          = COALESCE(ledger_accounts.root_type, VALUES(root_type)),
  account_code       = COALESCE(ledger_accounts.account_code, VALUES(account_code)),
  system_key         = COALESCE(ledger_accounts.system_key, VALUES(system_key));

-- Place the groups and system ledgers in the tree (only where unset).
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_assets'
   SET c.parent_id = p.id WHERE c.system_key IN ('grp_current_assets','grp_fixed_assets') AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_current_assets'
   SET c.parent_id = p.id WHERE c.system_key IN ('grp_bank','cash','receivable','input_tax') AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_bank'
   SET c.parent_id = p.id WHERE c.system_key = 'bank' AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_liabilities'
   SET c.parent_id = p.id WHERE c.system_key IN ('grp_current_liabilities','grp_loans') AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_current_liabilities'
   SET c.parent_id = p.id WHERE c.system_key IN ('payable','grp_taxes') AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_taxes'
   SET c.parent_id = p.id WHERE c.system_key = 'output_tax' AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_equity'
   SET c.parent_id = p.id WHERE c.system_key IN ('equity','opening_equity') AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_income'
   SET c.parent_id = p.id WHERE c.system_key = 'sales' AND c.parent_id IS NULL;
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_expenses'
   SET c.parent_id = p.id WHERE c.system_key IN ('expense','purchases','payroll','grp_indirect_expenses') AND c.parent_id IS NULL;

-- Existing tax / charge heads from before this migration: file them under
-- the matching group so the tree has no orphans.
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_taxes'
   SET c.parent_id = p.id, c.root_type = 'liability', c.account_nature = 'credit', c.statement_category = 'balance_sheet'
 WHERE c.system_key IS NULL AND c.root_type IS NULL AND c.account_type = 'tax';
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_income'
   SET c.parent_id = p.id, c.root_type = 'income', c.account_nature = 'credit', c.statement_category = 'profit_loss'
 WHERE c.system_key IS NULL AND c.root_type IS NULL AND c.account_type = 'income';
UPDATE ledger_accounts c JOIN ledger_accounts p ON p.system_key = 'grp_indirect_expenses'
   SET c.parent_id = p.id, c.root_type = 'expense', c.account_nature = 'debit', c.statement_category = 'profit_loss'
 WHERE c.system_key IS NULL AND c.root_type IS NULL AND c.account_type IN ('expense','other');

-- ---------------------------------------------------------------------
-- Journal vouchers (Journal Entry, Bank/Cash Payment & Receipt, Contra)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fin_journal_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_no VARCHAR(30) NOT NULL UNIQUE,
  voucher_type ENUM('journal','bank_payment','bank_receipt','cash_payment','cash_receipt','contra') NOT NULL DEFAULT 'journal',
  posting_date DATE NOT NULL,
  reference_no VARCHAR(60) DEFAULT NULL,
  reference_date DATE DEFAULT NULL,
  party_name VARCHAR(150) DEFAULT NULL,
  money_account_id INT UNSIGNED DEFAULT NULL,
  cost_center_id INT UNSIGNED DEFAULT NULL,
  project VARCHAR(100) DEFAULT NULL,
  narration VARCHAR(255) DEFAULT NULL,
  total_debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('draft','submitted','cancelled') NOT NULL DEFAULT 'draft',
  remarks TEXT DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  submitted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_journal_date (posting_date),
  FOREIGN KEY (cost_center_id) REFERENCES fin_cost_centers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fin_journal_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  cost_center_id INT UNSIGNED DEFAULT NULL,
  project VARCHAR(100) DEFAULT NULL,
  line_narration VARCHAR(255) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_journal_line_account (account_id),
  FOREIGN KEY (journal_id) REFERENCES fin_journal_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES ledger_accounts(id),
  FOREIGN KEY (cost_center_id) REFERENCES fin_cost_centers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bank reconciliation: one row per ledger posting marked as cleared.
CREATE TABLE IF NOT EXISTS fin_bank_clearances (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_type VARCHAR(20) NOT NULL,
  source_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  cleared_on DATE NOT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_clearance (source_type, source_id, account_id),
  FOREIGN KEY (account_id) REFERENCES ledger_accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Expenses: voucher no, payee, ledger, cost center, approval.
-- Existing rows (and payroll expenses from salary slips) stay approved.
-- ---------------------------------------------------------------------
ALTER TABLE expenses MODIFY payment_method ENUM('cash','bank_transfer','card','cheque','other','upi','auto_debit') NOT NULL DEFAULT 'cash';
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS expense_no VARCHAR(30) DEFAULT NULL AFTER id;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS vendor_id INT UNSIGNED DEFAULT NULL AFTER expense_no;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS payee VARCHAR(150) DEFAULT NULL AFTER vendor_id;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS account_id INT UNSIGNED DEFAULT NULL AFTER category;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER amount;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS paid_from_account_id INT UNSIGNED DEFAULT NULL AFTER payment_method;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS reference VARCHAR(120) DEFAULT NULL AFTER paid_from_account_id;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS cost_center_id INT UNSIGNED DEFAULT NULL AFTER reference;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS department_id INT UNSIGNED DEFAULT NULL AFTER cost_center_id;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS project VARCHAR(100) DEFAULT NULL AFTER department_id;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER project;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL AFTER status;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL AFTER approved_by;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL AFTER approved_at;
ALTER TABLE expenses ADD UNIQUE INDEX IF NOT EXISTS uniq_expense_no (expense_no);
UPDATE expenses SET expense_no = CONCAT('EXP-', LPAD(id, 6, '0')) WHERE expense_no IS NULL;

-- ---------------------------------------------------------------------
-- Tax & Compliance: GST / TDS / PF / ESI returns and payments
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fin_tax_filings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category ENUM('gst','tds','pf','esi','professional_tax','income_tax','other') NOT NULL DEFAULT 'gst',
  return_type VARCHAR(40) NOT NULL,
  period_month DATE NOT NULL,
  due_date DATE NOT NULL,
  filing_date DATE DEFAULT NULL,
  status ENUM('pending','filed','paid') NOT NULL DEFAULT 'pending',
  tax_liability DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  payment_date DATE DEFAULT NULL,
  challan_no VARCHAR(60) DEFAULT NULL,
  ack_no VARCHAR(60) DEFAULT NULL,
  remarks VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tax_return_period (return_type, period_month),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Budget & Planning
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fin_budgets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  fiscal_year_start DATE NOT NULL,
  department_id INT UNSIGNED DEFAULT NULL,
  category VARCHAR(100) DEFAULT NULL,
  cost_center_id INT UNSIGNED DEFAULT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  distribution ENUM('equal','custom') NOT NULL DEFAULT 'equal',
  status ENUM('draft','active','closed') NOT NULL DEFAULT 'active',
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (cost_center_id) REFERENCES fin_cost_centers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fin_budget_months (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  budget_id INT UNSIGNED NOT NULL,
  month_index TINYINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_budget_month (budget_id, month_index),
  FOREIGN KEY (budget_id) REFERENCES fin_budgets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Financial Reports: log of generated / downloaded reports
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fin_report_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_type VARCHAR(30) NOT NULL,
  report_name VARCHAR(150) NOT NULL,
  period_label VARCHAR(80) DEFAULT NULL,
  query_string VARCHAR(255) DEFAULT NULL,
  generated_by INT UNSIGNED DEFAULT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Finance configuration defaults (Configuration screen)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('fin_fy_start_month', '4'),
('fin_tds_applicable', '1'),
('fin_default_cost_center_id', ''),
('fin_expense_approval_limit', '0'),
('fin_prefix_journal', 'JV'),
('fin_prefix_bank_payment', 'BP'),
('fin_prefix_bank_receipt', 'BR'),
('fin_prefix_cash_payment', 'CP'),
('fin_prefix_cash_receipt', 'CR'),
('fin_prefix_contra', 'CT'),
('fin_prefix_expense', 'EXP'),
('fin_number_padding', '4');
