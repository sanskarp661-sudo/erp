<?php
/**
 * Shared bootstrap for the public integration API (api/v1/*.php). This is
 * session-free and CSRF-free by design: callers are the integrated
 * website's own backend server, not a browser, so it's authenticated by a
 * static API key (WEBSITE_API_KEY, sent as the X-API-Key header) instead.
 *
 * Every api/v1/*.php entry point starts with:
 *   require_once __DIR__ . '/../../includes/api.php';
 *   api_require_key();
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/integration_settings.php';
require_once __DIR__ . '/webhooks.php';

header('Content-Type: application/json');

/** Rejects the request with 401 unless a valid X-API-Key header is present. */
function api_require_key(): void
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expected = integration_setting('WEBSITE_API_KEY');
    if ($expected === '' || !hash_equals($expected, (string)$key)) {
        api_error('Invalid or missing API key.', 401);
    }
}

/** Parses the request body as JSON into an array ([] if absent/invalid). */
function api_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

/** Sends a JSON response and ends the request. */
function api_respond($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/** Sends a JSON {"error": ...} response and ends the request. */
function api_error(string $message, int $status = 400): void
{
    api_respond(['error' => $message], $status);
}

/** Absolute URL for a product's image, or null if it has none. products.image already stores a relative path like "uploads/products/xyz.jpg". */
function api_product_image_url(?string $image): ?string
{
    return $image ? base_url($image) : null;
}
