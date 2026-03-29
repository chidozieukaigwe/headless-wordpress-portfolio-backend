<?php
// Loader for nested headless-cache mu-plugin directory.
// WordPress only auto-loads PHP files directly in mu-plugins/, so include
// the nested implementation if present.
if (! defined('WP_CONTENT_DIR')) {
    // best-effort fallback to this directory's parent
    $mu_dir = dirname(__FILE__);
} else {
    $mu_dir = WP_CONTENT_DIR . '/mu-plugins';
}

$candidate = $mu_dir . '/headless-cache/headless-cache.php';
if (file_exists($candidate)) {
    require_once $candidate;
}