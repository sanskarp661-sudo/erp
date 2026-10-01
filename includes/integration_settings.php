<?php
/**
 * Website-integration secrets (WEBSITE_API_KEY, WEBSITE_WEBHOOK_URL) loaded
 * from a file that is NOT in git, so deploys never overwrite or blank them.
 *
 * Create ONE of these files on the server (the first one found wins):
 *   1. <folder above the ERP's web root>/erp-integration.php   (recommended: not web-accessible)
 *   2. config/integration.local.php                             (inside the ERP; git-ignored)
 *
 * File contents:
 *   <?php
 *   return [
 *       'WEBSITE_API_KEY'     => 'the-shared-key',
 *       'WEBSITE_WEBHOOK_URL' => 'https://store.mosaicengine.in/webhooks/erp.php',
 *   ];
 *
 * Values in that file take precedence; the constants in config/config.php
 * still work as a fallback, so nothing changes until the file exists.
 */

function integration_setting(string $name): string
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        $candidates = [
            dirname(__DIR__, 2) . '/erp-integration.php',
            dirname(__DIR__) . '/config/integration.local.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                $loaded = require $file;
                if (is_array($loaded)) {
                    $settings = $loaded;
                    break;
                }
            }
        }
    }
    if (isset($settings[$name]) && (string)$settings[$name] !== '') {
        return (string)$settings[$name];
    }
    return defined($name) ? (string)constant($name) : '';
}
