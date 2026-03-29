<?php
/**
 * Example Redis config values for local development.
 * Copy into wp-config.php or include from your local config when running Redis.
 */

// Redis connection
define('WP_REDIS_HOST', '127.0.0.1');
define('WP_REDIS_PORT', 6379);
define('WP_REDIS_TIMEOUT', 1);
define('WP_REDIS_READ_TIMEOUT', 1);

// Optional tuning
define('WP_REDIS_PREFIX', 'headless_');
define('WP_REDIS_MAXTTL', 86400); // default max TTL
define('WP_REDIS_PERSISTENT', true);
define('WP_REDIS_COMPRESSION', true);

// Example options used by object-cache drop-ins
// define('WP_REDIS_DATABASE', 0);
// define('WP_REDIS_IGBINARY', true);

/**
 * Example: set the frontend webhook values used by HeadlessCacheManager
 */
define('HEADLESS_FRONTEND_URL', 'http://localhost:3000');
define('HEADLESS_WEBHOOK_SECRET', 'replace-with-secure-secret');
