<?php
/**
 * Per-warehouse stock helpers. This is the ONLY code path allowed to
 * change stock — Delivery Notes deduct through it, GRNs add through it,
 * POS and manual Stock Entries use it too. Sales/Purchase Orders never
 * touch stock directly; only the documents that record actual physical
 * movement (Delivery Note, GRN, Stock Entry, POS sale) do.
 *
 * products.quantity is kept as a maintained total across all warehouses
 * (updated here alongside the per-warehouse stock_bins row) so existing
 * total-stock displays (product list, dashboard, low-stock alerts,
 * inventory report) keep working unchanged.
 */

require_once __DIR__ . '/functions.php';

/** Current quantity of a product at a specific warehouse (0 if no bin row yet). */
function warehouse_stock(int $productId, int $warehouseId): int
{
    $stmt = db()->prepare('SELECT quantity FROM stock_bins WHERE product_id = ? AND warehouse_id = ?');
    $stmt->execute([$productId, $warehouseId]);
    $qty = $stmt->fetchColumn();
    return $qty !== false ? (int)$qty : 0;
}

/**
 * Applies a signed quantity change to a product's stock at one warehouse,
 * keeps products.quantity in sync, and logs a stock_movements row. Must be
 * called inside a transaction the caller owns (it does not commit).
 * Throws RuntimeException if a negative delta would take the bin below zero.
 */
function stock_move(int $productId, int $warehouseId, int $delta, string $type, ?string $reference, ?string $notes, ?int $userId): void
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT quantity FROM stock_bins WHERE product_id = ? AND warehouse_id = ? FOR UPDATE');
    $stmt->execute([$productId, $warehouseId]);
    $current = $stmt->fetchColumn();

    if ($current === false) {
        if ($delta < 0) {
            throw new RuntimeException('Not enough stock in that warehouse.');
        }
        $pdo->prepare('INSERT INTO stock_bins (product_id, warehouse_id, quantity) VALUES (?, ?, ?)')
            ->execute([$productId, $warehouseId, $delta]);
    } else {
        $newQty = (int)$current + $delta;
        if ($newQty < 0) {
            throw new RuntimeException('Not enough stock in that warehouse.');
        }
        $pdo->prepare('UPDATE stock_bins SET quantity = ? WHERE product_id = ? AND warehouse_id = ?')
            ->execute([$newQty, $productId, $warehouseId]);
    }

    $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')->execute([$delta, $productId]);

    $pdo->prepare('INSERT INTO stock_movements (product_id, warehouse_id, type, quantity, reference, notes, created_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$productId, $warehouseId, $type, $delta, $reference, $notes, $userId]);
}

/** Non-group (leaf) warehouses only — the ones that can actually hold stock. */
function leaf_warehouses(): array
{
    return db()->query("SELECT id, name FROM warehouses WHERE is_group = 0 AND status = 'active' ORDER BY name")->fetchAll();
}

/** The warehouse new documents should default to (first active leaf warehouse). */
function default_warehouse_id(): ?int
{
    $id = db()->query("SELECT id FROM warehouses WHERE is_group = 0 AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/** Full ancestor-prefixed label for a warehouse, e.g. "All Warehouses / Stores". */
function warehouse_path(array $warehousesById, int $id): string
{
    $parts = [];
    $cursor = $warehousesById[$id] ?? null;
    while ($cursor) {
        array_unshift($parts, $cursor['name']);
        $cursor = $cursor['parent_id'] ? ($warehousesById[$cursor['parent_id']] ?? null) : null;
    }
    return implode(' / ', $parts);
}
