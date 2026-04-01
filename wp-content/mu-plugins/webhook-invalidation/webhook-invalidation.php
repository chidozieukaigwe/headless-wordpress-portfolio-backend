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

// Retry queue configuration
if (! defined('HEADLESS_WEBHOOK_QUEUE_OPTION')) {
    define('HEADLESS_WEBHOOK_QUEUE_OPTION', 'headless_webhook_queue');
}
if (! defined('HEADLESS_WEBHOOK_MAX_ATTEMPTS')) {
    define('HEADLESS_WEBHOOK_MAX_ATTEMPTS', 5);
}

/**
 * Enqueue a webhook payload for retry processing.
 *
 * @param array $payload
 * @return void
 */
function headless_enqueue_webhook(array $payload)
{
    $queue = get_option(HEADLESS_WEBHOOK_QUEUE_OPTION, []);
    $queue[] = [
        'payload' => $payload,
        'attempts' => 0,
        'last_attempt' => null,
    ];
    update_option(HEADLESS_WEBHOOK_QUEUE_OPTION, $queue);
}

/**
 * Process webhook queue: try sending queued payloads and remove on success.
 * Runs via WP-Cron hook `headless_retry_webhooks`.
 */
function headless_process_webhook_queue()
{
    // Lock processing to avoid concurrent runs
    if (get_transient('headless_webhook_queue_lock')) {
        return;
    }
    set_transient('headless_webhook_queue_lock', time(), 30);

    $queue = get_option(HEADLESS_WEBHOOK_QUEUE_OPTION, []);
    if (empty($queue) || ! is_array($queue)) {
        delete_transient('headless_webhook_queue_lock');
        return;
    }

    $new_queue = [];
    $webhook_url = get_option('headless_webhook_url');
    if (! $webhook_url) {
        if (defined('HEADLESS_WEBHOOK_URL')) {
            $webhook_url = HEADLESS_WEBHOOK_URL;
        } else {
            $webhook_url = getenv('HEADLESS_WEBHOOK_URL') ?: null;
        }
    }

    $webhook_secret = get_option('headless_webhook_secret');
    if (! $webhook_secret) {
        if (defined('HEADLESS_WEBHOOK_SECRET')) {
            $webhook_secret = HEADLESS_WEBHOOK_SECRET;
        } else {
            $webhook_secret = getenv('HEADLESS_WEBHOOK_SECRET') ?: null;
        }
    }

    foreach ($queue as $item) {
        $payload = isset($item['payload']) ? (array) $item['payload'] : [];
        $attempts = isset($item['attempts']) ? (int) $item['attempts'] : 0;

        if ($attempts >= HEADLESS_WEBHOOK_MAX_ATTEMPTS) {
            error_log('headless: dropping webhook after max attempts: ' . json_encode($payload));
            continue;
        }

        if (! $webhook_url) {
            $new_queue[] = $item; // keep in queue until configured
            continue;
        }

        $args = [
            'body' => wp_json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Webhook-Secret' => $webhook_secret,
            ],
            'timeout' => 10,
            'blocking' => true,
        ];

        $response = wp_remote_post($webhook_url, $args);
        $ok = false;
        if (! is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                $ok = true;
            }
        }

        if (! $ok) {
            $item['attempts'] = $attempts + 1;
            $item['last_attempt'] = time();
            $new_queue[] = $item;
        }
    }

    update_option(HEADLESS_WEBHOOK_QUEUE_OPTION, $new_queue);
    delete_transient('headless_webhook_queue_lock');
}

add_action('headless_retry_webhooks', 'headless_process_webhook_queue');

// Ensure a cron schedule exists (hourly) — lightweight default
if (! wp_next_scheduled('headless_retry_webhooks')) {
    wp_schedule_event(time() + 60, 'hourly', 'headless_retry_webhooks');
}

function headless_trigger_post_invalidation($post_id, $post = null, $update = null)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    $post_type = get_post_type($post_id);
    // Prefer site options, fall back to defined constants, then environment variables.
    $webhook_url = get_option('headless_webhook_url');
    if (! $webhook_url) {
        if (defined('HEADLESS_WEBHOOK_URL')) {
            $webhook_url = HEADLESS_WEBHOOK_URL;
        } else {
            $webhook_url = getenv('HEADLESS_WEBHOOK_URL') ?: null;
        }
    }

    $webhook_secret = get_option('headless_webhook_secret');
    if (! $webhook_secret) {
        if (defined('HEADLESS_WEBHOOK_SECRET')) {
            $webhook_secret = HEADLESS_WEBHOOK_SECRET;
        } else {
            $webhook_secret = getenv('HEADLESS_WEBHOOK_SECRET') ?: null;
        }
    }

    $payload = [
        'post_id'   => (int) $post_id,
        'post_type' => $post_type,
        'post_slug' => get_post_field('post_name', $post_id),
        'action'    => $update ? 'update' : 'save',
        'timestamp' => time(),
    ];

    // Compute frontend paths that should be revalidated by Next.js.
    $paths = [];
    if (! empty($payload['post_type']) && ! empty($payload['post_slug'])) {
        if ($payload['post_type'] === 'post') {
            $paths[] = '/posts/' . $payload['post_slug'];
        } else {
            $paths[] = '/' . $payload['post_type'] . '/' . $payload['post_slug'];
        }
    }

    // Always revalidate the primary listing page that may include this post.
    $paths[] = '/blog';

    // If post has a featured flag, also touch the homepage. Theme/plugins
    // may provide a meta key or set `$payload['featured']` before calling.
    if (! empty($payload['featured'])) {
        $paths[] = '/';
    }

    // Allow themes/plugins to customize mapping rules.
    $paths = (array) apply_filters('headless_revalidate_paths', $paths, $post_id, $post, $payload);
    $payload['paths'] = array_values(array_unique($paths));

    if ($webhook_url) {
        $result = wp_remote_post($webhook_url, [
            'body'    => wp_json_encode($payload),
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Webhook-Secret' => $webhook_secret,
            ],
            'timeout'  => 2,
            'blocking' => false,
        ]);

        /**
         * Action: headless_webhook_sent
         *
         * Fired after attempting to send the headless webhook. Allows tests
         * and operators to observe payloads and responses.
         *
         * @param array $payload The webhook payload sent.
         * @param mixed $result  The wp_remote_post() result (may be WP_Error).
         */
        do_action('headless_webhook_sent', $payload, $result);

        // If the non-blocking request returns a WP_Error or a non-2xx HTTP
        // response, enqueue for reliable retry via WP-Cron.
        $enqueue = false;
        if (is_wp_error($result)) {
            $enqueue = true;
        } else {
            $code = wp_remote_retrieve_response_code($result);
            if (! $code || $code < 200 || $code >= 300) {
                $enqueue = true;
            }
        }

        if ($enqueue) {
            headless_enqueue_webhook($payload);
        }
    }

    // Invalidate cached ID-lists that reference this post.
    try {
        // Prefer the optimizer's connectivity-checked Redis helper when available
        $redis = null;
        if (class_exists('MinimalDatabaseOptimizer') && method_exists('MinimalDatabaseOptimizer', 'get_instance')) {
            $optimizer = MinimalDatabaseOptimizer::get_instance();
            if (method_exists($optimizer, 'get_redis_instance')) {
                $redis = $optimizer->get_redis_instance();
            }
        }
        // Fallback: try to obtain raw redis instance (not connectivity-checked)
        if (! $redis) {
            global $wp_object_cache;
            if (isset($wp_object_cache) && method_exists($wp_object_cache, 'redis_instance')) {
                $redis = $wp_object_cache->redis_instance();
            }
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
                // Extra safe removals for different client implementations
                if (method_exists($redis, 'unlink')) {
                    try {
                        $redis->unlink($set_key);
                    } catch (Exception $e) {
                    }
                }
                if (method_exists($redis, 'sRem') && ! empty($members)) {
                    foreach ($members as $m) {
                        try {
                            if (method_exists($redis, 'sRem')) {
                                $redis->sRem($set_key, $m);
                            } elseif (method_exists($redis, 'srem')) {
                                $redis->srem($set_key, $m);
                            }
                        } catch (Exception $e) {
                            // ignore per-member failures
                        }
                    }
                }
            } catch (Exception $e) {
                // noop
            }
        }

        // Legacy array-based refs have been removed; no-op.
    } catch (Exception $e) {
        // never throw from invalidation path
    }
}

add_action('save_post', 'headless_trigger_post_invalidation', 10, 3);
add_action('before_delete_post', 'headless_trigger_post_invalidation');

// Optional: taxonomy/menu hooks can call the same invalidator or a specialized
// routine that deletes term refs. Keep lightweight to avoid admin blocking.

return;
