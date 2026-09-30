<?php
/**
 * Registry of importable entity types for the Excel data-import feature
 * (imports/*.php). Each entry describes one spreadsheet-importable entity:
 * its columns (used to build the downloadable template and the preview
 * table), which module permission gates it, and how to validate/commit one
 * row. Adding a new importable entity means adding one array entry plus a
 * pair of validate_row/commit_row functions — the upload/preview/commit
 * pages themselves are entirely generic over this registry.
 *
 * validate_row(array $row): array
 *   $row is the spreadsheet row as ['Header Label' => cell value, ...].
 *   Returns ['ok' => bool, 'errors' => string[], 'data' => array|null].
 *   Must not write to the database — preview runs this over every row
 *   before anything is committed, so the user sees every problem at once.
 *
 * commit_row(array $data): array
 *   $data is the 'data' array a passing validate_row returned. Only called
 *   during the real commit step, and expected to insert/update its own
 *   table(s) and return ['status' => 'success'|'error', 'message' =>
 *   string, 'record_id' => int|null].
 */

function importer_registry(): array
{
    return [
        'categories' => [
            'label' => 'Categories',
            'description' => 'Product categories, upserted by name.',
            'permission_module' => 'inventory',
            'columns' => [
                ['key' => 'Name', 'required' => true],
                ['key' => 'Description', 'required' => false],
            ],
            'validate_row' => 'importer_validate_categories',
            'commit_row' => 'importer_commit_categories',
        ],
        'brands' => [
            'label' => 'Brands',
            'description' => 'Product brands, upserted by name.',
            'permission_module' => 'inventory',
            'columns' => [
                ['key' => 'Name', 'required' => true],
                ['key' => 'Status', 'required' => false, 'hint' => 'active or inactive (default active)'],
            ],
            'validate_row' => 'importer_validate_brands',
            'commit_row' => 'importer_commit_brands',
        ],
        'uom' => [
            'label' => 'Units of Measure',
            'description' => 'Units of measure (pcs, kg, box, ...), upserted by name.',
            'permission_module' => 'inventory',
            'columns' => [
                ['key' => 'Name', 'required' => true],
                ['key' => 'Status', 'required' => false, 'hint' => 'active or inactive (default active)'],
            ],
            'validate_row' => 'importer_validate_uom',
            'commit_row' => 'importer_commit_uom',
        ],
    ];
}

function get_importer(string $key): ?array
{
    return importer_registry()[$key] ?? null;
}

/** Trims every cell and drops rows where every cell is blank. */
function importer_row_is_blank(array $row): bool
{
    foreach ($row as $v) {
        if (trim((string)$v) !== '') return false;
    }
    return true;
}

function importer_parse_status(mixed $value): string
{
    $v = strtolower(trim((string)$value));
    return $v === 'inactive' ? 'inactive' : 'active';
}

// --- Categories -------------------------------------------------------

function importer_validate_categories(array $row): array
{
    $name = trim((string)($row['Name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'errors' => ['Name is required.'], 'data' => null];
    }
    $description = trim((string)($row['Description'] ?? ''));
    return ['ok' => true, 'errors' => [], 'data' => ['name' => $name, 'description' => $description !== '' ? $description : null]];
}

function importer_commit_categories(array $data): array
{
    $stmt = db()->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
    $stmt->execute([$data['name']]);
    $existing = $stmt->fetch();
    if ($existing) {
        db()->prepare('UPDATE categories SET description = ? WHERE id = ?')->execute([$data['description'], $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing category.', 'record_id' => (int)$existing['id']];
    }
    db()->prepare('INSERT INTO categories (name, description) VALUES (?, ?)')->execute([$data['name'], $data['description']]);
    return ['status' => 'success', 'message' => 'Created new category.', 'record_id' => (int)db()->lastInsertId()];
}

// --- Brands -------------------------------------------------------------

function importer_validate_brands(array $row): array
{
    $name = trim((string)($row['Name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'errors' => ['Name is required.'], 'data' => null];
    }
    return ['ok' => true, 'errors' => [], 'data' => ['name' => $name, 'status' => importer_parse_status($row['Status'] ?? '')]];
}

function importer_commit_brands(array $data): array
{
    $stmt = db()->prepare('SELECT id FROM brands WHERE name = ? LIMIT 1');
    $stmt->execute([$data['name']]);
    $existing = $stmt->fetch();
    if ($existing) {
        db()->prepare('UPDATE brands SET status = ? WHERE id = ?')->execute([$data['status'], $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing brand.', 'record_id' => (int)$existing['id']];
    }
    db()->prepare('INSERT INTO brands (name, status) VALUES (?, ?)')->execute([$data['name'], $data['status']]);
    return ['status' => 'success', 'message' => 'Created new brand.', 'record_id' => (int)db()->lastInsertId()];
}

// --- Units of Measure -----------------------------------------------------

function importer_validate_uom(array $row): array
{
    $name = trim((string)($row['Name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'errors' => ['Name is required.'], 'data' => null];
    }
    return ['ok' => true, 'errors' => [], 'data' => ['name' => $name, 'status' => importer_parse_status($row['Status'] ?? '')]];
}

function importer_commit_uom(array $data): array
{
    $stmt = db()->prepare('SELECT id FROM uom WHERE name = ? LIMIT 1');
    $stmt->execute([$data['name']]);
    $existing = $stmt->fetch();
    if ($existing) {
        db()->prepare('UPDATE uom SET status = ? WHERE id = ?')->execute([$data['status'], $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing unit.', 'record_id' => (int)$existing['id']];
    }
    db()->prepare('INSERT INTO uom (name, status) VALUES (?, ?)')->execute([$data['name'], $data['status']]);
    return ['status' => 'success', 'message' => 'Created new unit.', 'record_id' => (int)db()->lastInsertId()];
}
