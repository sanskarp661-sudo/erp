<?php
/**
 * Minimal Cashfree Payment Gateway client (Payment Links API), used only
 * by the POS "Pay via Cashfree" flow.
 *
 * Plain cURL, no Composer/SDK — this project deliberately has no build
 * step or dependency manager (see README). Every request field, header,
 * endpoint, and the webhook signature algorithm below were taken
 * directly from Cashfree's official PHP SDK source (cashfree/
 * cashfree-pg-sdk-php on GitHub/Packagist), not guessed, since this
 * moves real money.
 */

require_once __DIR__ . '/functions.php';

/**
 * Cashfree credentials, preferring the values saved from Settings >
 * Integrations (the `settings` table — survives deploys, unlike
 * config.php) over the config.php constants of the same name.
 */
function cashfree_setting(string $name): string
{
    $map = [
        'CLIENT_ID' => 'cashfree_client_id', 'CLIENT_SECRET' => 'cashfree_client_secret',
        'ENV' => 'cashfree_env', 'PRODUCT' => 'cashfree_product',
    ];
    $dbValue = setting($map[$name] ?? '');
    if ($dbValue !== null && $dbValue !== '') {
        return $dbValue;
    }
    $constant = 'CASHFREE_' . $name;
    return defined($constant) ? trim((string)constant($constant)) : '';
}

function cashfree_configured(): bool
{
    return cashfree_setting('CLIENT_ID') !== '' && cashfree_setting('CLIENT_SECRET') !== '';
}

function cashfree_api_base(): string
{
    $env = cashfree_setting('ENV') ?: 'sandbox';
    return $env === 'production' ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
}

/**
 * Which Cashfree product to use for POS checkout. 'orders' (Standard
 * Checkout, via the payment_session_id + their JS widget) is Cashfree's
 * core product and is enabled by default for any approved merchant
 * account. 'payment_link' (a plain URL we render as our own QR code) is
 * a separate add-on product that needs explicit enabling by Cashfree
 * support — kept here as a fallback, switchable with one config line if
 * you'd rather use it once it's turned on for your account.
 */
function cashfree_product(): string
{
    return cashfree_setting('PRODUCT') === 'payment_link' ? 'payment_link' : 'orders';
}

/**
 * Creates a Cashfree Order (Standard Checkout) and returns a
 * payment_session_id for Cashfree's client-side JS widget to open.
 *
 * @param array $params order_id, amount, customer_id, customer_name, customer_phone, customer_email (optional), notify_url, return_url
 * @return array Decoded response body (includes 'payment_session_id', 'order_id', 'cf_order_id', ...).
 * @throws RuntimeException on any transport error or a non-2xx response from Cashfree.
 */
function cashfree_create_order(array $params): array
{
    if (!cashfree_configured()) {
        throw new RuntimeException('Cashfree is not configured. Add your Client ID / Secret from Settings > Integrations.');
    }

    $body = [
        'order_id' => $params['order_id'],
        'order_amount' => (float)$params['amount'],
        'order_currency' => 'INR',
        'customer_details' => [
            'customer_id' => $params['customer_id'],
            'customer_phone' => $params['customer_phone'],
            'customer_name' => $params['customer_name'] ?? '',
            'customer_email' => $params['customer_email'] ?? '',
        ],
        'order_meta' => [
            'notify_url' => $params['notify_url'],
            'return_url' => $params['return_url'],
        ],
        'order_note' => $params['purpose'] ?? 'POS Sale',
    ];

    $response = cashfree_request('POST', '/orders', $body, '2023-08-01');

    if (empty($response['payment_session_id'])) {
        $message = $response['message'] ?? 'Cashfree did not return a payment session.';
        throw new RuntimeException($message);
    }

    return $response;
}

/**
 * Creates a Cashfree Payment Link. Fallback product — see
 * cashfree_product(). Not used unless CASHFREE_PRODUCT='payment_link'.
 *
 * @param array $params link_id, amount, customer_name, customer_phone, customer_email (optional), notify_url, return_url, purpose
 * @return array Decoded response body (includes 'link_url', 'link_id', 'cf_link_id', ...).
 * @throws RuntimeException on any transport error or a non-2xx response from Cashfree.
 */
function cashfree_create_payment_link(array $params): array
{
    if (!cashfree_configured()) {
        throw new RuntimeException('Cashfree is not configured. Add your Client ID / Secret from Settings > Integrations.');
    }

    $body = [
        'link_id' => $params['link_id'],
        'link_amount' => (float)$params['amount'],
        'link_currency' => 'INR',
        'link_purpose' => $params['purpose'] ?? 'POS Sale',
        'customer_details' => [
            'customer_phone' => $params['customer_phone'],
            'customer_name' => $params['customer_name'] ?? '',
            'customer_email' => $params['customer_email'] ?? '',
        ],
        'link_notify' => ['send_sms' => false, 'send_email' => false],
        'link_meta' => [
            'notify_url' => $params['notify_url'],
            'return_url' => $params['return_url'],
            'upi_intent' => true,
        ],
        'link_expiry_time' => date('c', strtotime('+30 minutes')),
    ];

    $response = cashfree_request('POST', '/links', $body, '2022-09-01');

    if (empty($response['link_url'])) {
        $message = $response['message'] ?? 'Cashfree did not return a payment link.';
        throw new RuntimeException($message);
    }

    return $response;
}

/**
 * Low-level signed request to the Cashfree Payment Gateway API.
 */
function cashfree_request(string $method, string $path, ?array $body = null, string $apiVersion = '2022-09-01'): array
{
    $url = cashfree_api_base() . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-client-id: ' . cashfree_setting('CLIENT_ID'),
            'x-client-secret: ' . cashfree_setting('CLIENT_SECRET'),
            'x-api-version: ' . $apiVersion,
        ],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('Could not reach Cashfree: ' . $error);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Cashfree returned an unreadable response (HTTP ' . $httpCode . ').');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $decoded['message'] ?? ('Cashfree request failed (HTTP ' . $httpCode . ').');
        throw new RuntimeException($message);
    }

    return $decoded;
}

/**
 * Verifies a Cashfree webhook's authenticity.
 *
 * Algorithm confirmed from Cashfree's official PHP SDK
 * (Cashfree::PGVerifyWebhookSignature): base64(hmac_sha256(timestamp .
 * rawBody, client_secret)) must equal the x-webhook-signature header.
 * Never process a webhook payload before this returns true.
 */
function cashfree_verify_webhook_signature(string $signature, string $rawBody, string $timestamp): bool
{
    if (!cashfree_configured() || $signature === '' || $timestamp === '') {
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, cashfree_setting('CLIENT_SECRET'), true));
    return hash_equals($expected, $signature);
}
