<?php

/**
 * Integration test for WP-CLI headless revalidate command.
 *
 * This test calls the revalidate command class directly and mocks HTTP
 * responses using the `pre_http_request` filter so no external HTTP is performed.
 */

class Test_CLI_Revalidate_Integration extends WP_UnitTestCase
{
    public function test_cli_revalidate_sends_webhook_blocking()
    {
        // Create a post to revalidate
        $post_id = $this->factory->post->create([
            'post_title' => 'CLI Revalidate IT',
            'post_status' => 'publish',
        ]);

        // Ensure webhook URL is configured
        update_option('headless_webhook_url', 'http://example.test/api/revalidate');
        update_option('headless_webhook_secret', 'test-secret');

        // Mock HTTP requests to always return 200 OK
        add_filter('pre_http_request', function ($preempt, $r, $url) {
            return [
                'headers' => [],
                'body' => '{}',
                'response' => ['code' => 200, 'message' => 'OK'],
            ];
        }, 10, 3);

        // Provide a minimal WP_CLI stub so the command can call WP_CLI::* methods
        if (! class_exists('WP_CLI')) {
            class WP_CLI
            {
                public static $messages = [];
                public static function error($msg)
                {
                    throw new Exception($msg);
                }
                public static function warning($msg)
                {
                    self::$messages[] = ['warning' => $msg];
                }
                public static function success($msg)
                {
                    self::$messages[] = ['success' => $msg];
                }
                public static function log($msg)
                {
                    self::$messages[] = ['log' => $msg];
                }
            }
        }

        // Instantiate the CLI command and run it (blocking)
        $cmd = new Headless_Revalidate_Command();
        $cmd->revalidate([$post_id], ['blocking' => true]);

        // Verify the local WP_CLI stub recorded a success message
        $found = false;
        foreach (WP_CLI::$messages as $m) {
            if (isset($m['success'])) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected WP_CLI::success() to be called');
    }
}
