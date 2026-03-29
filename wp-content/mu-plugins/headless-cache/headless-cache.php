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
        try {
            if (class_exists('Redis')) {
                $r = new Redis();
                $host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
                $port = defined('WP_REDIS_PORT') ? WP_REDIS_PORT : 6379;
                $r->connect($host, $port, defined('WP_REDIS_TIMEOUT') ? WP_REDIS_TIMEOUT : 1);
                $this->redis = $r;
            }
        } catch (Exception $e) {
            // Fail open: leave $this->redis null and continue serving dynamic responses
            $this->redis = null;
        }
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
        if (! $this->redis) {
            return null;
        }

        try {
            $key = $this->get_cache_key($request);
            $cached = $this->redis->get($key);
            if ($cached) {
                return json_decode($cached, true);
            }
        } catch (Exception $e) {
            // ignore and fail open
        }

        return null;
    }

    /**
     * Cache REST API responses (expects $response to be rest response or array)
     */
    public function cache_api_response($request, $response)
    {
        if (! $this->redis) {
            return $response;
        }

        try {
            if (strtoupper($request->get_method()) !== 'GET') {
                return $response;
            }

            $payload = rest_ensure_response($response);
            $data = $payload->get_data();
            $json = wp_json_encode($data);

            $key = $this->get_cache_key($request);
            $ttl = $this->calculate_ttl($request);
            if ($json !== false) {
                $this->redis->setex($key, $ttl, $json);
            }
        } catch (Exception $e) {
            // ignore failures — don't block response
        }

        return $response;
    }

    /**
     * Invalidate cache when content changes
     */
    public function invalidate_content_cache($post_id)
    {
        if (! $this->redis) {
            return;
        }

        try {
            // delete specific post key
            $post_key = $this->prefix . 'post:' . $post_id;
            $this->redis->del($post_key);

            // naive: clear keys by pattern for listings and homepage
            if (method_exists($this->redis, 'keys')) {
                $pattern = $this->prefix . '*';
                foreach ($this->redis->keys($pattern) as $k) {
                    $this->redis->del($k);
                }
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

return;