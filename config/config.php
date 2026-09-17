<?php
/**
 * ERP configuration.
 * Fill in your Hostinger MySQL database details below, then upload this
 * whole project via File Manager. See README.md for step-by-step setup.
 */

// --- Database connection (from hPanel > Databases > MySQL Databases) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u250373472_demo_erp');
define('DB_USER', 'u250373472_demo_erp');
define('DB_PASS', 'Sanskar@1216');
define('DB_CHARSET', 'utf8mb4');

// --- App ---
define('APP_NAME', 'ERP System');
// Base URL of the app, no trailing slash. Used for redirects/links.
// e.g. 'https://yourdomain.com' or 'https://yourdomain.com/erp'
define('APP_URL', 'https://erp.mosaicengine.in');

// Set to false in production once everything works.
define('APP_DEBUG', true);

// A random secret used to sign CSRF tokens. Change this to a long random
// string before deploying (e.g. generate one at random.org or run
// php -r "echo bin2hex(random_bytes(32));" locally).
define('APP_SECRET', '693d38dd22adc89cfcba59106abb25739eb6232489796b0b1e5295c6f207417e');
