<?php
/**
 * GET /api/v1/categories.php
 *
 * Lists categories for the storefront's navigation/filtering. Products
 * reference these via products.category (see the "category" field on
 * GET /api/v1/products.php).
 */

require_once __DIR__ . '/../../includes/api.php';
api_require_key();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Method not allowed.', 405);
}

$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

api_respond(['categories' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $categories)]);
