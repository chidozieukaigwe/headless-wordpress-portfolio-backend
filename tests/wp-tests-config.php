<?php

/**
 * Local WP PHPUnit config for this project.
 *
 * Adjust values if your local test DB or host differ.
 */

// Database settings for the tests database (create this DB manually).
if (! defined('DB_NAME')) {
    define('DB_NAME', 'local');
}
if (! defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (! defined('DB_PASSWORD')) {
    define('DB_PASSWORD', 'root');
}
if (! defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}

// Test site constants
if (! defined('WP_TESTS_DOMAIN')) {
    define('WP_TESTS_DOMAIN', 'chidodesigns.local');
}
if (! defined('WP_TESTS_EMAIL')) {
    define('WP_TESTS_EMAIL', 'test@example.com');
}
if (! defined('WP_TESTS_TITLE')) {
    define('WP_TESTS_TITLE', 'Headless Test Site');
}

// Path to the project ABSPATH (your WP install root)
if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// Path to PHP binary used during tests. Prefer the runtime PHP binary when
// available (PHP exposes `PHP_BINARY`), otherwise fall back to `/usr/bin/php`.
if (! defined('WP_PHP_BINARY')) {
    if (defined('PHP_BINARY') && PHP_BINARY) {
        define('WP_PHP_BINARY', PHP_BINARY);
    } else {
        define('WP_PHP_BINARY', '/usr/bin/php');
    }
}

// Optional: table prefix for test DB
// NOTE: the WP test bootstrap defines `WP_TESTS_TABLE_PREFIX` itself from a
// local `$table_prefix` variable. To avoid "already defined" warnings, do
// not define the `WP_TESTS_TABLE_PREFIX` constant here. If you need to
// override it, set the environment variable `WP_PHPUNIT__TABLE_PREFIX`.

// Prevent automated translation updates from breaking tests
if (! defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH') && file_exists(__DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php')) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', realpath(__DIR__ . '/../vendor/yoast/phpunit-polyfills'));
}