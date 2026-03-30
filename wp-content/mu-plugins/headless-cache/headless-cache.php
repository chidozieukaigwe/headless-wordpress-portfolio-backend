<?php

/**
 * Mu-plugin: HeadlessCacheManager
 *
 * Minimal, resilient skeleton that provides Redis-backed REST response
 * caching for a headless WordPress site. This is intentionally small and
 * safe for local/dev: it fails open if Redis is not available.
 */

if (! defined('ABSPATH')) {
    exit;
}

class HeadlessCacheManager
{
    private static $instance = null;
    private $redis = null;
    private $prefix = 'headless:api:';
    private $default_ttl = 3600;

    private function __construct()
    {
        // Intentionally avoid creating a direct Redis client here.
        // All cache reads/writes should go through the WordPress object-cache API
        // (`wp_cache_get`/`wp_cache_set`). If the active object-cache drop-in
        // exposes a Redis instance, we'll use that only for health checks.
        $this->redis = null;
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function get_cache_key($request)
    {
        $route = (string) $request->get_route();
        $params = $request->get_query_params();

        // Remove volatile auth/nonce params
        unset($params['_wpnonce'], $params['_wp_http_referer'], $params['preview']);

        ksort($params);
        $params_string = http_build_query($params);
        return $this->prefix . md5($route . '?' . $params_string);
    }

    private function calculate_ttl($request)
    {
        $route = (string) $request->get_route();
        if (strpos($route, '/posts') !== false) {
            return 3600;
        }
        if (strpos($route, '/pages') !== false) {
            return 86400;
        }
        if (strpos($route, '/project') !== false) {
            return 7200;
        }
        return $this->default_ttl;
    }

    /**
     * Get cached API response (returns a PHP array or null)
     */
    public function get_cached_response($request)
    {
        $key = $this->get_cache_key($request);

        // Prefer WP object-cache (drop-in) when available
        if (function_exists('wp_cache_get')) {
            try {
                $found = null;
                $value = wp_cache_get($key, 'headless-cache', false, $found);
                if ($found) {
                    error_log('headless-cache: cache hit (wp_cache) ' . $key);
                    return $value;
                }
                error_log('headless-cache: cache miss (wp_cache) ' . $key);
            } catch (Exception $e) {
                error_log('headless-cache: wp_cache_get error: ' . $e->getMessage());
            }
        }
        return null;
    }

    /**
     * Cache REST API responses (expects $response to be rest response or array)
     */
    public function cache_api_response($request, $response)
    {
        try {
            if (strtoupper($request->get_method()) !== 'GET') {
                return $response;
            }

            $payload = rest_ensure_response($response);
            $data = $payload->get_data();

            $key = $this->get_cache_key($request);
            $ttl = $this->calculate_ttl($request);

            // Prefer WP object-cache
            if (function_exists('wp_cache_set')) {
                $ok = wp_cache_set($key, $data, 'headless-cache', $ttl);
                if ($ok) {
                    $size = strlen(wp_json_encode($data));
                    error_log(sprintf('headless-cache: cached (wp_cache) %s ttl=%d size=%d', $key, $ttl, $size));
                } else {
                    error_log('headless-cache: wp_cache_set failed for ' . $key);
                }

                return $response;
            }
            // If wp_cache_set isn't available, we do not attempt a raw Redis set.
            // This keeps all cache operations going through the object-cache API
            // to avoid key-format mismatches between different backends.
        } catch (Exception $e) {
            error_log('headless-cache: set error: ' . $e->getMessage());
            // ignore failures — don't block response
        }

        return $response;
    }

    /**
     * Invalidate cache when content changes
     */
    public function invalidate_content_cache($post_id)
    {
        try {
            $post_key = $this->prefix . 'post:' . $post_id;

            // Prefer WP object-cache deletion
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($post_key, 'headless-cache');
                // Do not perform pattern deletes via Redis keys() here — expensive
                // and may not use the same physical key format as `wp_cache_*`.
            } else {
                // If wp_cache_delete isn't available, do nothing — we prefer the
                // object-cache API to manage cache state.
            }

            // trigger frontend purge if configured
            $frontend_url = get_option('headless_frontend_url');
            $webhook_secret = get_option('headless_webhook_secret');
            if ($frontend_url) {
                wp_remote_post(rtrim($frontend_url, '/') . '/api/revalidate', [
                    'body'    => wp_json_encode(['post_id' => $post_id]),
                    'headers' => [
                        'X-Webhook-Secret' => $webhook_secret,
                        'Content-Type'     => 'application/json',
                    ],
                    'timeout' => 2,
                    'blocking' => false,
                ]);
            }
        } catch (Exception $e) {
            // ignore
        }
    }
}

// Wiring: short-circuit read and write post-dispatch
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    // If another handler already returned a response, keep it
    if ($result !== null) {
        return $result;
    }

    $cached = HeadlessCacheManager::getInstance()->get_cached_response($request);
    if ($cached !== null) {
        return rest_ensure_response($cached);
    }

    return $result;
}, 10, 3);

add_filter('rest_post_dispatch', function ($result, $server, $request) {
    HeadlessCacheManager::getInstance()->cache_api_response($request, $result);
    return $result;
}, 10, 3);

// Invalidate caches on content changes
add_action('save_post', function ($post_id) {
    HeadlessCacheManager::getInstance()->invalidate_content_cache($post_id);
});

add_action('deleted_post', function ($post_id) {
    HeadlessCacheManager::getInstance()->invalidate_content_cache($post_id);
});

// Health endpoint for local checks: /wp-json/headless-cache/v1/health
add_action('rest_api_init', function () {
    register_rest_route('headless-cache/v1', '/health', [
        'methods' => 'GET',
        'callback' => function () {
            $mgr = HeadlessCacheManager::getInstance();
            $ok = false;
            $info = [
                'wp_cache' => null,
                'redis' => null,
            ];

            // Test WP object-cache if available
            if (function_exists('wp_cache_set') && function_exists('wp_cache_get')) {
                try {
                    $test_key = 'headless-cache-health-test';
                    wp_cache_set($test_key, 'ok', 'headless-cache', 5);
                    $found = null;
                    $val = wp_cache_get($test_key, 'headless-cache', false, $found);
                    $info['wp_cache'] = $found ? 'ok' : 'miss';
                    if ($found) {
                        $ok = true;
                    }
                } catch (Exception $e) {
                    $info['wp_cache'] = 'error';
                }
            } else {
                $info['wp_cache'] = 'not-available';
            }

            // Also report on Redis connection if the active object-cache exposes it
            try {
                global $wp_object_cache;
                if (isset($wp_object_cache) && method_exists($wp_object_cache, 'redis_instance')) {
                    $redis = $wp_object_cache->redis_instance();
                    if ($redis && method_exists($redis, 'ping')) {
                        $pong = $redis->ping();
                        $info['redis'] = ($pong === '+PONG' || $pong === true) ? 'ok' : $pong;
                        $ok = $ok || true;
                    } else {
                        $info['redis'] = 'not-connected';
                    }
                } else {
                    $info['redis'] = 'not-available';
                }
            } catch (Exception $e) {
                $info['redis'] = 'error';
            }

            return rest_ensure_response([
                'ok' => $ok,
                'info' => $info,
            ]);
        },
        'permission_callback' => function ($request) {
            // Allow unprotected access in local/dev environments
            $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : (getenv('WP_ENV') ?: null);
            if ($env && in_array($env, ['local', 'development'], true)) {
                return true;
            }

            // Validate shared secret header
            $secret = defined('HEADLESS_WEBHOOK_SECRET') ? HEADLESS_WEBHOOK_SECRET : getenv('HEADLESS_WEBHOOK_SECRET');
            $header = '';
            if (is_object($request) && method_exists($request, 'get_header')) {
                $header = $request->get_header('x-webhook-secret') ?: $request->get_header('x-health-secret') ?: '';
            }
            if ($secret && is_string($header) && hash_equals((string) $secret, (string) $header)) {
                return true;
            }

            // Allow loopback requests
            $remote = $_SERVER['REMOTE_ADDR'] ?? '';
            if (in_array($remote, ['127.0.0.1', '::1'], true)) {
                return true;
            }

            // Fallback: require an authenticated admin user
            if (function_exists('current_user_can') && current_user_can('manage_options')) {
                return true;
            }

            return false;
        },
    ]);
});

return;