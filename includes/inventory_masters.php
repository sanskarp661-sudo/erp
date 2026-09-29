<?php
/**
 * Helpers for the simple Inventory masters (Categories, Item Categories,
 * Brands, Units of Measure). Each master's list and form page sets $cfg and
 * includes inventory/_master_list.php or inventory/_master_form.php. $cfg:
 *   table       master table name
 *   title       plural label ("Brands")
 *   singular    singular label ("Brand")
 *   list / form the page file names
 *   icon        Font Awesome class
 *   fk          products column holding the master's id, or null for UOM
 *               (products.unit stores the unit's name)
 *   filter      products.php query parameter to open matching products
 *   status      true if the table has an active/inactive status column
 *   description true if the table has a description column
 *   placeholder optional placeholder for the name field
 *   status_help optional help text under the status field
 */
require_once __DIR__ . '/auth.php';

/**
 * Per-master usage: product count, units on hand and stock value at cost,
 * keyed by master id. UOM is matched by name in PHP, so no text comparison
 * happens across tables in SQL (the live server mixes collations).
 */
function inv_master_usage(array $cfg, array $rows): array
{
    $usage = [];
    if ($cfg['fk']) {
        $stmt = db()->query("SELECT {$cfg['fk']} k, COUNT(*) products,
            SUM(GREATEST(quantity, 0)) units, SUM(GREATEST(quantity, 0) * cost_price) value
          FROM products WHERE {$cfg['fk']} IS NOT NULL GROUP BY {$cfg['fk']}");
        foreach ($stmt as $r) $usage[(int)$r['k']] = $r;
        return $usage;
    }
    $byName = [];
    $stmt = db()->query("SELECT unit k, COUNT(*) products,
        SUM(GREATEST(quantity, 0)) units, SUM(GREATEST(quantity, 0) * cost_price) value
      FROM products GROUP BY unit");
    foreach ($stmt as $r) $byName[(string)$r['k']] = $r;
    foreach ($rows as $row) {
        if (isset($byName[$row['name']])) $usage[(int)$row['id']] = $byName[$row['name']];
    }
    return $usage;
}

/** Products using one master row (for the form's usage panel). */
function inv_master_products(array $cfg, array $row, int $limit = 6): array
{
    if ($cfg['fk']) {
        $stmt = db()->prepare("SELECT id, sku, name, image, quantity, unit FROM products WHERE {$cfg['fk']} = ? ORDER BY name LIMIT $limit");
        $stmt->execute([(int)$row['id']]);
    } else {
        $stmt = db()->prepare("SELECT id, sku, name, image, quantity, unit FROM products WHERE unit = ? ORDER BY name LIMIT $limit");
        $stmt->execute([(string)$row['name']]);
    }
    return $stmt->fetchAll();
}

function inv_master_filter_url(array $cfg, array $row): string
{
    return 'products.php?' . http_build_query([$cfg['filter'] => $cfg['fk'] ? (int)$row['id'] : $row['name']]);
}
