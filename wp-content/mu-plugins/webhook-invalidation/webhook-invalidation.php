<?php

/**
 * Safe webhook invalidation handler
 *
 * Sends a non-blocking webhook to the frontend and invalidates cached
 * ID-lists using per-post Redis SETs when available. Falls back to
 * array-based refs stored via `wp_cache_*` when Redis isn't exposed.
 */

if (! defined('ABSPATH')) {
    exit;
}

function headless_trigger_post_invalidation($post_id, $post = null, $update = null)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    $post_type = get_post_type($post_id);
    $webhook_url = get_option('headless_webhook_url');
    $webhook_secret = get_option('headless_webhook_secret');

    $payload = [
        'post_id'   => (int) $post_id,
        'post_type' => $post_type,
        'post_slug' => get_post_field('post_name', $post_id),
        'action'    => $update ? 'update' : 'save',
        'timestamp' => time(),
    ];

    if ($webhook_url) {
        wp_remote_post($webhook_url, [
            'body'    => wp_json_encode($payload),
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Webhook-Secret' => $webhook_secret,
            ],
            'timeout'  => 2,
            'blocking' => false,
        ]);
    }

    // Invalidate cached ID-lists that reference this post.
    try {
        global $wp_object_cache;
        $redis = null;
        if (isset($wp_object_cache) && method_exists($wp_object_cache, 'redis_instance')) {
            $redis = $wp_object_cache->redis_instance();
        }

        $set_key = 'headless:refs:set:post:' . (int) $post_id;

        if ($redis) {
            // Read members and delete referenced cache keys
            try {
                if (method_exists($redis, 'sMembers')) {
                    $members = $redis->sMembers($set_key) ?: [];
                } elseif (method_exists($redis, 'smembers')) {
                    $members = $redis->smembers($set_key) ?: [];
                } else {
                    $members = $redis->smembers($set_key) ?: [];
                }
            } catch (Exception $e) {
                $members = [];
            }

            if (! empty($members)) {
                foreach ($members as $k) {
                    wp_cache_delete($k, 'headless-ids');
                }
            }

            // Remove the set; prefer `del` then `delete` method names.
            try {
                if (method_exists($redis, 'del')) {
                    $redis->del($set_key);
                } elseif (method_exists($redis, 'delete')) {
                    $redis->delete($set_key);
                }
            } catch (Exception $e) {
                // noop
            }
        } else {
            // Fallback to array-based refs stored via wp_cache
            $ref_key = 'headless:refs:post:' . (int) $post_id;
            if (function_exists('wp_cache_get')) {
                $refs = wp_cache_get($ref_key, 'headless-refs') ?: [];
                if (! empty($refs)) {
                    foreach ($refs as $k) {
                        wp_cache_delete($k, 'headless-ids');
                    }
                }
                if (function_exists('wp_cache_delete')) {
                    wp_cache_delete($ref_key, 'headless-refs');
                }
            }
        }
    } catch (Exception $e) {
        // never throw from invalidation path
    }
}

add_action('save_post', 'headless_trigger_post_invalidation', 10, 3);
add_action('before_delete_post', 'headless_trigger_post_invalidation');

// Optional: taxonomy/menu hooks can call the same invalidator or a specialized
// routine that deletes term refs. Keep lightweight to avoid admin blocking.

return;