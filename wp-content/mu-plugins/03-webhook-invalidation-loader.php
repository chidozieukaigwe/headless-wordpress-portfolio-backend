<?php

/**
 * Loader for webhook invalidation mu-plugin
 */
if (! defined('ABSPATH')) {
    // mu-plugins are loaded inside WP; avoid direct execution
    return;
}

$file = __DIR__ . '/webhook-invalidation/webhook-invalidation.php';
if (file_exists($file)) {
    require_once $file;
}

return;
