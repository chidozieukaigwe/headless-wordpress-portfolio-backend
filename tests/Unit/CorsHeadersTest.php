<?php

/**
 * Tests CORS headers are added for allowed origins via rest_pre_serve_request
 */
class CorsHeadersTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Ensure the CORS handler function is loaded (mu-plugins are loaded by bootstrap)
    }

    public function test_cors_headers_added_for_allowed_origin()
    {
        // Define allowed origins constant expected by the mu-plugin
        if (! defined('ALLOWED_ORIGINS')) {
            define('ALLOWED_ORIGINS', array('http://allowed.example'));
        }

        // Simulate an incoming origin
        $_SERVER['HTTP_ORIGIN'] = 'http://allowed.example';

        // Build a dummy WP_REST_Request
        $request = new WP_REST_Request('GET', '/wp/v2/project');

        // Call the CORS handler via the filter function if available
        if (function_exists('chidodesigns_add_cors_headers')) {
            // Clear headers list before call
            @header_remove();
            $served = false;
            $result = null;
            $returned = chidodesigns_add_cors_headers($served, $result, $request);

            $headers = headers_list();

            $found = false;
            foreach ($headers as $h) {
                if (stripos($h, 'Access-Control-Allow-Origin:') === 0) {
                    $found = true;
                    $this->assertStringContainsString('http://allowed.example', $h);
                }
            }

            // If headers were already sent in this environment, the mu-plugin will
            // record the headers on a global for tests to inspect.
            if (! $found && isset($GLOBALS['chidodesigns_cors_test_headers'])) {
                foreach ($GLOBALS['chidodesigns_cors_test_headers'] as $h) {
                    if (stripos($h, 'Access-Control-Allow-Origin:') === 0) {
                        $found = true;
                        $this->assertStringContainsString('http://allowed.example', $h);
                    }
                }
            }

            $this->assertTrue($found, 'Access-Control-Allow-Origin header not found');
        } else {
            $this->markTestSkipped('CORS handler function not available');
        }
    }
}
