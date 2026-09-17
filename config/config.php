<?php
/**
 * ERP configuration.
 * Fill in your Hostinger MySQL database details below, then upload this
 * whole project via File Manager. See README.md for step-by-step setup.
 */

// --- Database connection (from hPanel > Databases > MySQL Databases) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456789_erp');
define('DB_USER', 'u123456789_erp');
define('DB_PASS', 'change-me');
define('DB_CHARSET', 'utf8mb4');

// --- App ---
define('APP_NAME', 'ERP System');
// Base URL of the app, no trailing slash. Used for redirects/links.
// e.g. 'https://yourdomain.com' or 'https://yourdomain.com/erp'
define('APP_URL', '');

// Set to false in production once everything works.
define('APP_DEBUG', true);

// A random secret used to sign CSRF tokens. Change this to a long random
// string before deploying (e.g. generate one at random.org or run
// php -r "echo bin2hex(random_bytes(32));" locally).
define('APP_SECRET', 'change-this-to-a-long-random-string');
