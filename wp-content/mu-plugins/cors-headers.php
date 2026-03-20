<?php

/**
 * Mu-plugin: CORS headers for REST API
 *
 * Moved from wp-config.php because core functions (add_action) are not
 * available while wp-config.php is executing.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'chidodesigns_custom_cors_headers');
function chidodesigns_custom_cors_headers()
{
    header_remove('X-Powered-By');
    $http_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

    if (defined('ALLOWED_ORIGINS') && in_array($http_origin, ALLOWED_ORIGINS, true)) {
        header("Access-Control-Allow-Origin: {$http_origin}");
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
        header('Vary: Origin');
    }
}

add_action('init', 'chidodesigns_handle_preflight_options', 0);
function chidodesigns_handle_preflight_options()
{
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $http_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        if (defined('ALLOWED_ORIGINS') && in_array($http_origin, ALLOWED_ORIGINS, true)) {
            header("Access-Control-Allow-Origin: {$http_origin}");
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
            header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
            header('Vary: Origin');
        }
        exit;
    }
}
