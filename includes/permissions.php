<?php
/**
 * Multi-role permission model. A user can hold several roles at once;
 * what they can do is the union of what each of their roles grants.
 *
 * Every role can VIEW all business modules (Inventory, Supply Chain,
 * Procurement, Sales, POS, HRMS, CRM, Finance) — require_login() is
 * enough to view any list/detail page. Only EDIT rights differ:
 *  - edit_all: can edit every module, plus company Settings and Print
 *    Formats (but not necessarily manage Users — see manage_users).
 *  - edit_module: can edit only this one module (module keys match
 *    includes/modules.php's $MODULES array keys).
 *  - manage_module: within that one module, also allowed to do the
 *    "risky" actions (delete a record, cancel/void an order or invoice) —
 *    not just create/edit. Roles without this can still create and edit,
 *    just not delete or cancel.
 *  - manage_users: can create/edit users and change their role
 *    assignments. Deliberately separate from edit_all — the "Admin" role
 *    can edit everything else but not this.
 */

const ROLE_DEFS = [
    'system_admin'    => ['label' => 'System Admin',    'edit_all' => true,  'edit_module' => null,          'manage_module' => false, 'manage_users' => true],
    'admin'            => ['label' => 'Admin',            'edit_all' => true,  'edit_module' => null,          'manage_module' => false, 'manage_users' => false],
    'system_viewer'    => ['label' => 'System Viewer',    'edit_all' => false, 'edit_module' => null,          'manage_module' => false, 'manage_users' => false],
    'purchase_manager' => ['label' => 'Purchase Manager', 'edit_all' => false, 'edit_module' => 'procurement', 'manage_module' => true,  'manage_users' => false],
    'purchase_user'    => ['label' => 'Purchase User',    'edit_all' => false, 'edit_module' => 'procurement', 'manage_module' => false, 'manage_users' => false],
    'sales_manager'    => ['label' => 'Sales Manager',    'edit_all' => false, 'edit_module' => 'sales',       'manage_module' => true,  'manage_users' => false],
    'sales_user'       => ['label' => 'Sales User',       'edit_all' => false, 'edit_module' => 'sales',       'manage_module' => false, 'manage_users' => false],
    'hr'               => ['label' => 'HR',               'edit_all' => false, 'edit_module' => 'hrms',        'manage_module' => true,  'manage_users' => false],
    'crm'              => ['label' => 'CRM',               'edit_all' => false, 'edit_module' => 'crm',         'manage_module' => true,  'manage_users' => false],
    'accounts_manager' => ['label' => 'Accounts Manager', 'edit_all' => false, 'edit_module' => 'finance',     'manage_module' => true,  'manage_users' => false],
    'accounts_user'    => ['label' => 'Accounts User',    'edit_all' => false, 'edit_module' => 'finance',     'manage_module' => false, 'manage_users' => false],
    'pos'              => ['label' => 'POS',               'edit_all' => false, 'edit_module' => 'pos',         'manage_module' => true,  'manage_users' => false],
    'supply_chain'     => ['label' => 'Supply Chain',     'edit_all' => false, 'edit_module' => 'supply-chain', 'manage_module' => true,  'manage_users' => false],
    'item_manager'     => ['label' => 'Item Manager',     'edit_all' => false, 'edit_module' => 'inventory',   'manage_module' => true,  'manage_users' => false],
];

function role_label(string $key): string
{
    return ROLE_DEFS[$key]['label'] ?? $key;
}

/** Role keys held by the logged-in user (empty array if not logged in). */
function current_user_roles(): array
{
    $u = current_user();
    return $u['roles'] ?? [];
}

function user_has_role(string $key): bool
{
    return in_array($key, current_user_roles(), true);
}

/** Can create/edit records in this module (but not necessarily delete/cancel — see can_manage_module). */
function can_edit_module(string $moduleKey): bool
{
    foreach (current_user_roles() as $r) {
        $def = ROLE_DEFS[$r] ?? null;
        if ($def && ($def['edit_all'] || $def['edit_module'] === $moduleKey)) {
            return true;
        }
    }
    return false;
}

/** Can also delete/cancel/void records in this module. */
function can_manage_module(string $moduleKey): bool
{
    foreach (current_user_roles() as $r) {
        $def = ROLE_DEFS[$r] ?? null;
        if (!$def) continue;
        if ($def['edit_all']) return true;
        if ($def['edit_module'] === $moduleKey && $def['manage_module']) return true;
    }
    return false;
}

/** Users/Settings/Print Formats aren't business modules — visible only to System Admin, Admin, and System Viewer. */
function can_view_admin_section(): bool
{
    foreach (current_user_roles() as $r) {
        if (in_array($r, ['system_admin', 'admin', 'system_viewer'], true)) return true;
    }
    return false;
}

/** Can edit Settings / Print Formats (but not necessarily Users — see can_manage_users). */
function can_edit_admin_section(): bool
{
    foreach (current_user_roles() as $r) {
        if ((ROLE_DEFS[$r]['edit_all'] ?? false)) return true;
    }
    return false;
}

/** Can create/edit users and change role assignments. System Admin only. */
function can_manage_users(): bool
{
    foreach (current_user_roles() as $r) {
        if ((ROLE_DEFS[$r]['manage_users'] ?? false)) return true;
    }
    return false;
}

function deny_access(): void
{
    http_response_code(403);
    require __DIR__ . '/header.php';
    echo '<div class="alert alert-danger">You do not have permission to perform this action.</div>';
    require __DIR__ . '/footer.php';
    exit;
}

function require_module_edit(string $moduleKey): void
{
    require_login();
    if (!can_edit_module($moduleKey)) deny_access();
}

function require_module_manage(string $moduleKey): void
{
    require_login();
    if (!can_manage_module($moduleKey)) deny_access();
}

function require_admin_section(): void
{
    require_login();
    if (!can_view_admin_section()) deny_access();
}

function require_admin_edit(): void
{
    require_login();
    if (!can_edit_admin_section()) deny_access();
}

function require_manage_users(): void
{
    require_login();
    if (!can_manage_users()) deny_access();
}
