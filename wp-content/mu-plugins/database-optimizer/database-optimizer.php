<?php

/**
 * Minimal Database Optimizer for headless cache misses
 *
 * - Short-lived cached lists of post IDs for REST collection queries
 * - Safe defaults: small TTL, read-only during requests, no schema changes
 */

if (! defined('ABSPATH')) {
    exit;
}

class MinimalDatabaseOptimizer
{
    private static $instance = null;
    private $prefix = 'headless:ids:';
    private $cache_group = 'headless-ids';
    private $ttl = 120; // seconds - short-lived to avoid staleness

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function get_cache_key($request)
    {
        $route = (string) $request->get_route();
        $params = $request->get_query_params();

        // Remove volatile params that shouldn't change result identity
        unset($params['_wpnonce'], $params['_wp_http_referer'], $params['preview']);

        ksort($params);
        $params_string = http_build_query($params);
        return $this->prefix . md5($route . '?' . $params_string);
    }

    /**
     * Try to short-circuit expensive WP_Query by using cached IDs
     * Filter: rest_post_query (args, request)
     */
    public function optimize_rest_query($args, $request)
    {
        // Only act for GET requests
        if (! is_object($request) || strtoupper($request->get_method()) !== 'GET') {
            return $args;
        }

        $key = $this->get_cache_key($request);

        if (function_exists('wp_cache_get')) {
            try {
                $found = null;
                $ids = wp_cache_get($key, $this->cache_group, false, $found);
                if ($found && is_array($ids) && ! empty($ids)) {
                    // Force WP_Query to use the cached IDs and preserve order
                    $args['post__in'] = $ids;
                    $args['orderby'] = 'post__in';
                }
            } catch (Exception $e) {
                // ignore cache errors and continue
            }
        }

        return $args;
    }

    /**
     * After REST dispatch, cache the list of IDs for collection responses
     * Filter: rest_post_dispatch (result, server, request)
     */
    public function cache_response_ids($result, $server, $request)
    {
        try {
            if (! is_object($request) || strtoupper($request->get_method()) !== 'GET') {
                return $result;
            }

            $response = rest_ensure_response($result);
            $data = $response->get_data();

            if (! is_array($data) || empty($data)) {
                return $result;
            }

            $ids = [];
            foreach ($data as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $ids[] = (int) $item['id'];
                } elseif (is_object($item) && isset($item->id)) {
                    $ids[] = (int) $item->id;
                }
            }

            if (! empty($ids) && function_exists('wp_cache_set')) {
                $key = $this->get_cache_key($request);
                // Best-effort cache; short TTL to avoid staleness without complex invalidation
                wp_cache_set($key, $ids, $this->cache_group, $this->ttl);

                // Also record per-post references so we can invalidate only affected caches
                try {
                    foreach ($ids as $post_id) {
                        $ref_key = 'headless:refs:post:' . (int) $post_id;
                        $refs = wp_cache_get($ref_key, 'headless-refs') ?: [];
                        $refs[] = $key;
                        // keep unique and bounded list (last 50)
                        $refs = array_values(array_slice(array_unique($refs), -50));
                        wp_cache_set($ref_key, $refs, 'headless-refs', DAY_IN_SECONDS);
                    }
                } catch (Exception $e) {
                    // ignore ref tracking errors
                }
            }
        } catch (Exception $e) {
            // ignore errors to keep request flow safe
        }

        return $result;
    }
}

// Wire up the minimal optimizer
add_filter('rest_post_query', function ($args, $request) {
    return MinimalDatabaseOptimizer::get_instance()->optimize_rest_query($args, $request);
}, 10, 2);

add_filter('rest_post_dispatch', function ($result, $server, $request) {
    return MinimalDatabaseOptimizer::get_instance()->cache_response_ids($result, $server, $request);
}, 10, 3);

/**
 * Targeted invalidation: remove cached ID lists that referenced this post.
 * Keeps invalidation lightweight and avoids a global flush.
 */
add_action('save_post', function ($post_id) {
    try {
        $ref_key = 'headless:refs:post:' . (int) $post_id;
        if (function_exists('wp_cache_get')) {
            $refs = wp_cache_get($ref_key, 'headless-refs') ?: [];
            if (! empty($refs)) {
                foreach ($refs as $k) {
                    wp_cache_delete($k, 'headless-ids');
                }
            }
            wp_cache_delete($ref_key, 'headless-refs');
        }
    } catch (Exception $e) {
        // ignore invalidation errors
    }
});

add_action('deleted_post', function ($post_id) {
    try {
        $ref_key = 'headless:refs:post:' . (int) $post_id;
        if (function_exists('wp_cache_get')) {
            $refs = wp_cache_get($ref_key, 'headless-refs') ?: [];
            if (! empty($refs)) {
                foreach ($refs as $k) {
                    wp_cache_delete($k, 'headless-ids');
                }
            }
            wp_cache_delete($ref_key, 'headless-refs');
        }
    } catch (Exception $e) {
        // ignore invalidation errors
    }
});

return;