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

/**
 * Use `rest_pre_serve_request` to add CORS headers safely when the REST API
 * is serving a request. This avoids sending headers during plugin load and
 * prevents "headers already sent" errors in CLI test environments.
 */
add_filter('rest_pre_serve_request', 'chidodesigns_add_cors_headers', 10, 3);
function chidodesigns_add_cors_headers($served, $result, $request)
{
    // Remove any X-Powered-By header for cleanliness
    header_remove('X-Powered-By');

    $http_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

    if (defined('ALLOWED_ORIGINS') && in_array($http_origin, ALLOWED_ORIGINS, true)) {
        header("Access-Control-Allow-Origin: {$http_origin}");
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Preview-Nonce');
        header('Vary: Origin');

        // Handle preflight: respond with headers and short-circuit
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
            // Terminate execution for preflight with no body
            echo '';
            return true; // Indicate the response has been served
        }
    }

    return $served;
}
