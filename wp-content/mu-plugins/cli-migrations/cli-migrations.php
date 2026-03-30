<?php

/**
 * WP-CLI migrations for headless cache refs
 *
 * Usage: `wp headless migrate-refs [--dry-run]`
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('WP_CLI') || ! WP_CLI) {
    return;
}

class Headless_Migrations_Command
{
    /**
     * Migrate array-based per-post refs stored via `wp_cache_set`
     * into Redis SETs when a Redis client is available from the
     * active object-cache drop-in.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be migrated without writing.
     */
    public function migrate_refs($args, $assoc_args)
    {
        $dry = isset($assoc_args['dry-run']);

        global $wp_object_cache;
        $redis = null;
        if (isset($wp_object_cache) && method_exists($wp_object_cache, 'redis_instance')) {
            $redis = $wp_object_cache->redis_instance();
        }

        if (! $redis) {
            WP_CLI::warning('No Redis client available from object-cache; aborting migration.');
            return;
        }

        $per_page = 500;
        $offset = 0;
        $migrated = 0;

        while (true) {
            $posts = get_posts([
                'post_type'   => 'any',
                'numberposts' => $per_page,
                'offset'      => $offset,
                'post_status' => 'any',
                'fields'      => 'ids',
            ]);

            if (empty($posts)) {
                break;
            }

            foreach ($posts as $id) {
                $ref_key = 'headless:refs:post:' . (int) $id;
                $refs = function_exists('wp_cache_get') ? wp_cache_get($ref_key, 'headless-refs') : [];
                if (empty($refs) || ! is_array($refs)) {
                    continue;
                }

                $set_key = 'headless:refs:set:post:' . (int) $id;
                $count = 0;
                foreach ($refs as $k) {
                    if ($dry) {
                        $count++;
                        continue;
                    }

                    try {
                        if (method_exists($redis, 'sAdd')) {
                            $redis->sAdd($set_key, $k);
                        } elseif (method_exists($redis, 'sadd')) {
                            $redis->sadd($set_key, $k);
                        } else {
                            $redis->sadd($set_key, $k);
                        }
                        $count++;
                    } catch (Exception $e) {
                        WP_CLI::warning('Failed to add member to set for post ' . $id);
                    }
                }

                if (! $dry) {
                    // remove old array-based refs
                    if (function_exists('wp_cache_delete')) {
                        wp_cache_delete($ref_key, 'headless-refs');
                    }
                }

                if ($count) {
                    $migrated += $count;
                    WP_CLI::log(sprintf('Post %d: %d refs %s', $id, $count, $dry ? '(dry)' : 'migrated'));
                }
            }

            $offset += $per_page;
        }

        WP_CLI::success(sprintf('Migration complete — total refs migrated: %d', $migrated));
    }

    /**
     * List per-post refs for debugging.
     *
     * ## USAGE
     *
     * wp headless list-refs <post_id>
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID to inspect.
     */
    public function list_refs($args, $assoc_args)
    {
        $post_id = isset($args[0]) ? (int) $args[0] : 0;
        if (! $post_id) {
            WP_CLI::error('Usage: wp headless list-refs <post_id>');
            return;
        }

        global $wp_object_cache;
        $redis = null;
        if (isset($wp_object_cache) && method_exists($wp_object_cache, 'redis_instance')) {
            $redis = $wp_object_cache->redis_instance();
        }

        $set_key = 'headless:refs:set:post:' . $post_id;
        $ref_key = 'headless:refs:post:' . $post_id;

        WP_CLI::log("Inspecting refs for post {$post_id}");

        if ($redis) {
            WP_CLI::log("Redis-backed refs (set: {$set_key}):");
            try {
                if (method_exists($redis, 'sMembers')) {
                    $members = $redis->sMembers($set_key) ?: [];
                } elseif (method_exists($redis, 'smembers')) {
                    $members = $redis->smembers($set_key) ?: [];
                } else {
                    $members = $redis->smembers($set_key) ?: [];
                }
            } catch (Exception $e) {
                WP_CLI::warning('Failed to read Redis set: ' . $e->getMessage());
                $members = [];
            }

            if (empty($members)) {
                WP_CLI::log('  (none)');
            } else {
                foreach ($members as $m) {
                    WP_CLI::log('  ' . $m);
                }
            }
        } else {
            WP_CLI::log('No Redis client exposed by object-cache drop-in.');
        }

        WP_CLI::log("Array-based refs (cache key: {$ref_key}):");
        $refs = function_exists('wp_cache_get') ? wp_cache_get($ref_key, 'headless-refs') : [];
        if (empty($refs)) {
            WP_CLI::log('  (none)');
        } else {
            foreach ($refs as $r) {
                WP_CLI::log('  ' . $r);
            }
        }
    }
}

WP_CLI::add_command('headless', 'Headless_Migrations_Command');

return;