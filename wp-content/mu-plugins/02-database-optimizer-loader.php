<?php
// Loader for Minimal Database Optimizer mu-plugin
if (! defined('ABSPATH')) {
    exit;
}

$path = __DIR__ . '/database-optimizer/database-optimizer.php';
if (file_exists($path)) {
    require_once $path;
}

return;
