<?php
/**
 * Website-integration secrets (WEBSITE_API_KEY, WEBSITE_WEBHOOK_URL) loaded
 * from a file that is NOT in git, so deploys never overwrite or blank them.
 *
 * Put the file in ONE of these places (the first one found wins):
 *   1. <folder above the ERP's web root>/erp-integration.php
 *   2. config/integration.local.php        (recommended; git-ignored, not web-readable)
 *   3. integration.local.php in the ERP's main folder (git-ignored)
 *
 * Either format works:
 *   <?php
 *   return [
 *       'WEBSITE_API_KEY'     => 'the-shared-key',
 *       'WEBSITE_WEBHOOK_URL' => 'https://store.mosaicengine.in/webhooks/erp.php',
 *   ];
 * or
 *   <?php
 *   define('WEBSITE_API_KEY', 'the-shared-key');
 *   define('WEBSITE_WEBHOOK_URL', 'https://store.mosaicengine.in/webhooks/erp.php');
 *
 * The file is read as text (never executed), so a typo in it can't break
 * the ERP. Values found there take precedence; the constants in
 * config/config.php still work as a fallback.
 */

const INTEGRATION_SETTING_NAMES = ['WEBSITE_API_KEY', 'WEBSITE_WEBHOOK_URL'];

/** ['file' => relative path of the file used (or null), 'values' => [name => value]] */
function integration_settings_source(): array
{
    static $source = null;
    if ($source !== null) return $source;

    $erpRoot = dirname(__DIR__);
    $candidates = [
        '../erp-integration.php' => dirname($erpRoot) . '/erp-integration.php',
        'config/integration.local.php' => $erpRoot . '/config/integration.local.php',
        'integration.local.php' => $erpRoot . '/integration.local.php',
    ];
    $source = ['file' => null, 'values' => []];
    foreach ($candidates as $label => $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        $text = (string)file_get_contents($path);
        $source['file'] = $label;
        foreach (INTEGRATION_SETTING_NAMES as $name) {
            // Matches  'NAME' => 'value'   and   define('NAME', 'value')  (single or double quotes).
            $pattern = '/[\'"]' . $name . '[\'"]\s*(?:=>|,)\s*(?:\'([^\']*)\'|"([^"]*)")/';
            if (preg_match($pattern, $text, $m)) {
                $value = trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
                if ($value !== '') $source['values'][$name] = $value;
            }
        }
        break;
    }
    return $source;
}

function integration_setting(string $name): string
{
    $source = integration_settings_source();
    if (isset($source['values'][$name])) {
        return $source['values'][$name];
    }
    return defined($name) ? trim((string)constant($name)) : '';
}

/**
 * The key used both to authenticate incoming api/v1/*.php calls (see
 * includes/api.php's api_require_key()) and to sign outgoing webhooks
 * (includes/webhooks.php): the oldest active row in api_clients (Settings
 * > Integrations) if any exist, else the legacy WEBSITE_API_KEY. Empty
 * string means nothing is configured yet.
 */
function primary_api_key(): string
{
    $stmt = db()->query("SELECT api_key FROM api_clients WHERE status = 'active' ORDER BY id LIMIT 1");
    $key = $stmt->fetchColumn();
    return $key !== false ? $key : integration_setting('WEBSITE_API_KEY');
}
