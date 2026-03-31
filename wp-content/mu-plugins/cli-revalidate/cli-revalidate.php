<?php

/**
 * WP-CLI command: wp headless revalidate <post_id> [--blocking]
 *
 * Triggers the configured Headless webhook for a post. Useful in CI/deploy
 * scripts to force frontend revalidation. By default the request is
 * non-blocking; pass --blocking to wait for the HTTP response.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('WP_CLI') || ! WP_CLI) {
    return;
}

class Headless_Revalidate_Command
{
    public function revalidate($args, $assoc_args)
    {
        $post_id = isset($args[0]) ? (int) $args[0] : 0;
        if (! $post_id) {
            WP_CLI::error('Usage: wp headless revalidate <post_id> [--blocking]');
            return;
        }

        $blocking = isset($assoc_args['blocking']);

        $post = get_post($post_id);
        if (! $post) {
            WP_CLI::error('Post not found: ' . $post_id);
            return;
        }

        $payload = [
            'post_id'   => $post_id,
            'post_type' => get_post_type($post_id),
            'post_slug' => get_post_field('post_name', $post_id),
            'action'    => 'revalidate',
            'timestamp' => time(),
        ];

        // Compute default paths (same logic as webhook-invalidation handler)
        $paths = [];
        if (! empty($payload['post_type']) && ! empty($payload['post_slug'])) {
            if ($payload['post_type'] === 'post') {
                $paths[] = '/posts/' . $payload['post_slug'];
            } else {
                $paths[] = '/' . $payload['post_type'] . '/' . $payload['post_slug'];
            }
        }
        $paths[] = '/blog';
        if (! empty($payload['featured'])) {
            $paths[] = '/';
        }

        $paths = (array) apply_filters('headless_revalidate_paths', $paths, $post_id, $post, $payload);
        $payload['paths'] = array_values(array_unique($paths));

        // Resolve webhook URL & secret (same precedence as plugin)
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

        if (! $webhook_url) {
            WP_CLI::error('No headless webhook URL configured.');
            return;
        }

        $args = [
            'body'    => wp_json_encode($payload),
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Webhook-Secret' => $webhook_secret,
            ],
            'timeout' => 30,
            'blocking' => (bool) $blocking,
        ];

        $result = wp_remote_post($webhook_url, $args);

        if (is_wp_error($result)) {
            WP_CLI::warning('HTTP request failed: ' . $result->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($result);
        if ($code >= 200 && $code < 300) {
            WP_CLI::success('Revalidate webhook sent (HTTP ' . $code . ')');
        } else {
            WP_CLI::warning('Revalidate webhook responded with HTTP ' . $code);
        }
    }
}

WP_CLI::add_command('headless revalidate', 'Headless_Revalidate_Command');

return;
