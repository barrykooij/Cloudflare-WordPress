<?php

/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Loads a real WordPress install with the plugin active, so tests can assert
 * against actual hooks, options and HTTP calls. Runs inside the wp-env
 * `cli` container, where WordPress lives at WP_ABSPATH (default /var/www/html)
 * and the plugin is mapped to wp-content/plugins/cloudflare.
 */

$cloudflareAbspath = getenv('WP_ABSPATH');
if (! is_string($cloudflareAbspath) || $cloudflareAbspath === '') {
    $cloudflareAbspath = '/var/www/html';
}
$cloudflareWpLoad = rtrim($cloudflareAbspath, '/') . '/wp-load.php';

if (! is_readable($cloudflareWpLoad)) {
    fwrite(
        STDERR,
        "Integration bootstrap could not find wp-load.php at {$cloudflareWpLoad}.\n"
        . "Run this suite inside wp-env: `npm run env:test:start` then `npm run test:integration`.\n"
    );
    exit(1);
}

// Load WordPress the way admin-ajax.php does, so the plugin registers the
// admin and AJAX hooks it only adds when is_admin() is true.
define('WP_ADMIN', true);

require_once $cloudflareWpLoad;

require_once dirname(__DIR__) . '/vendor/autoload.php';
