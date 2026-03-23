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
    $http_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

    if (defined('ALLOWED_ORIGINS') && in_array($http_origin, ALLOWED_ORIGINS, true)) {
        $cors_headers = array(
            "Access-Control-Allow-Origin: {$http_origin}",
            'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Credentials: true',
            'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Preview-Nonce',
            'Vary: Origin',
        );

        $is_preflight = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS';
        if ($is_preflight) {
            $cors_headers[] = 'Access-Control-Max-Age: 86400';
        }

        if (! headers_sent()) {
            // Remove any X-Powered-By header for cleanliness
            @header_remove('X-Powered-By');

            foreach ($cors_headers as $h) {
                @header($h);
            }

            if ($is_preflight) {
                echo '';
                return true;
            }
        } else {
            // In CLI/test environments headers may already be sent; store headers
            // for tests to inspect rather than emitting them (avoids warnings).
            $GLOBALS['chidodesigns_cors_test_headers'] = $cors_headers;
            if ($is_preflight) {
                return true;
            }
        }
    }

    return $served;
}
