<?php

/**
 * Loader for CLI migrations mu-plugin
 */
if (! defined('ABSPATH')) {
    return;
}

$file = __DIR__ . '/cli-migrations/cli-migrations.php';
if (file_exists($file)) {
    require_once $file;
}

return;
