<?php
/**
 * Outgoing webhooks to the integrated website, fired when a website-origin
 * order (sales_orders.sales_channel = 'Online Store') changes status, or
 * its linked Delivery Note is delivered. Fire-and-forget: a failed or slow
 * webhook is logged and never blocks the status transition that triggered
 * it — the website is expected to also poll api/v1/order_status.php as a
 * fallback for any webhook it missed.
 */

/**
 * POSTs a signed JSON event to WEBSITE_WEBHOOK_URL. No-op if that constant
 * is undefined or blank (webhook integration not configured).
 */
function notify_website(string $event, array $data): void
{
    require_once __DIR__ . '/integration_settings.php';
    // A webhook URL saved from Settings > Integrations overrides the legacy
    // WEBSITE_WEBHOOK_URL (config.php or the untracked integration file).
    $url = setting('website_webhook_url') ?: integration_setting('WEBSITE_WEBHOOK_URL');
    if (!$url) {
        return;
    }

    $body = json_encode(['event' => $event, 'data' => $data, 'sent_at' => date('c')]);
    $secret = primary_api_key();
    $signature = hash_hmac('sha256', $body, $secret);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Signature: ' . $signature],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    // Deliberately ignore curl_errno()/http status here — a webhook miss
    // must never surface as a failure of the status change that caused it.
    curl_close($ch);
}
