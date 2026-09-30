<?php
/**
 * GET /api/v1/products.php
 *
 * Lists active products for catalog sync. Supports:
 *   ?sku=ABC-123        — a single product by SKU (returns {product: {...}} or 404)
 *   ?since=2026-01-01T00:00:00Z — only products updated at/after this time (ISO 8601;
 *                          anything strtotime() accepts also works), for incremental sync
 *   ?page=1&per_page=50 — pagination (per_page capped at 200)
 *
 * Every product includes "available_quantity" = on-hand stock minus stock
 * already committed to other (non-cancelled, not-yet-delivered) Sales
 * Orders — the same "reserved" calculation the ERP's own Stock Balance
 * Report uses — so the storefront doesn't oversell items that are on hand
 * but already spoken for.
 */

require_once __DIR__ . '/../../includes/api.php';
api_require_key();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Method not allowed.', 405);
}

$reservedSql = "SELECT soi.product_id, SUM(soi.quantity) qty
    FROM sales_order_items soi
    JOIN sales_orders so ON so.id = soi.order_id
    WHERE so.status <> 'cancelled'
      AND NOT EXISTS (SELECT 1 FROM delivery_notes dn WHERE dn.sales_order_id = so.id AND dn.status = 'delivered')
    GROUP BY soi.product_id";
$reserved = [];
foreach (db()->query($reservedSql) as $row) {
    $reserved[(int)$row['product_id']] = (int)$row['qty'];
}

function api_product_images_map(array $productIds): array
{
    if (!$productIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = db()->prepare("SELECT product_id, image FROM product_images WHERE product_id IN ($placeholders) ORDER BY sort_order, id");
    $stmt->execute($productIds);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['product_id']][] = api_product_image_url($row['image']);
    }
    return $map;
}

function api_product_row(array $p, array $reserved, array $images = []): array
{
    $available = (int)$p['quantity'] - ($reserved[(int)$p['id']] ?? 0);
    return [
        'id' => (int)$p['id'],
        'sku' => $p['sku'],
        'name' => $p['name'],
        'description' => $p['description'],
        'category' => $p['category_name'],
        'brand' => $p['brand_name'],
        'image_url' => api_product_image_url($p['image']),
        'images' => $images,
        'unit' => $p['unit'],
        'price' => (float)$p['selling_price'],
        'currency' => 'INR',
        'quantity_on_hand' => (int)$p['quantity'],
        'available_quantity' => max(0, $available),
        'status' => $p['status'],
        'updated_at' => date('c', strtotime($p['updated_at'])),
    ];
}

$baseSelect = "SELECT p.id, p.sku, p.name, p.description, p.image, p.unit, p.selling_price, p.quantity, p.status, p.updated_at,
    c.name category_name, b.name brand_name
  FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  LEFT JOIN brands b ON b.id = p.brand_id";

if (input('sku') !== '') {
    $stmt = db()->prepare($baseSelect . " WHERE p.sku = ? AND p.status = 'active'");
    $stmt->execute([input('sku')]);
    $product = $stmt->fetch();
    if (!$product) {
        api_error('Product not found.', 404);
    }
    $images = api_product_images_map([(int)$product['id']]);
    api_respond(['product' => api_product_row($product, $reserved, $images[(int)$product['id']] ?? [])]);
}

$where = "WHERE p.status = 'active'";
$params = [];
if (input('since') !== '') {
    $since = strtotime(input('since'));
    if ($since === false) {
        api_error('Invalid "since" — use an ISO 8601 timestamp.');
    }
    $where .= ' AND p.updated_at >= ?';
    $params[] = date('Y-m-d H:i:s', $since);
}

$page = max(1, (int)input('page', '1'));
$perPage = min(200, max(1, (int)input('per_page', '50')));
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare("SELECT COUNT(*) FROM products p $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = db()->prepare("$baseSelect $where ORDER BY p.id ASC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$imagesMap = api_product_images_map(array_column($rows, 'id'));
$products = array_map(fn($p) => api_product_row($p, $reserved, $imagesMap[(int)$p['id']] ?? []), $rows);

api_respond([
    'products' => $products,
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'has_more' => $offset + count($products) < $total,
]);
