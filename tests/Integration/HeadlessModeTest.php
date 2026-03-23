<?php

/**
 * Integration tests for headless mode functionality
 */

class HeadlessModeTest extends WP_UnitTestCase
{

    /**
     * Test that frontend redirects to React app
     */
    public function test_frontend_redirect_to_react()
    {
        // Simulate frontend request
        $_SERVER['REQUEST_URI'] = '/about';

        // Capture redirect
        add_filter('wp_redirect', function ($location, $status) {
            $this->assertEquals('http://localhost:5173/about', $location);
            // Return the original location so WP's redirect flow can continue
            return $location;
        }, 10, 2);

        do_action('template_redirect');
    }

    /**
     * Test that admin area is accessible
     */
    public function test_admin_area_accessible()
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin';

        $redirect_called = false;
        add_filter('wp_redirect', function ($location, $status) use (&$redirect_called) {
            $redirect_called = true;
            // Return the location unchanged
            return $location;
        }, 10, 2);

        do_action('template_redirect');

        $this->assertFalse($redirect_called);
    }

    /**
     * Test that REST API is accessible
     */
    public function test_rest_api_accessible()
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';

        $redirect_called = false;
        add_filter('wp_redirect', function ($location, $status) use (&$redirect_called) {
            $redirect_called = true;
            return $location;
        }, 10, 2);

        do_action('template_redirect');

        $this->assertFalse($redirect_called);
    }

    protected function tearDown(): void
    {
        // Clean up any global request URI we set during tests
        if (isset($_SERVER['REQUEST_URI'])) {
            unset($_SERVER['REQUEST_URI']);
        }

        // Remove any 'is_admin' filter added by tests to avoid bleeding state
        remove_filter('is_admin', '__return_true');

        parent::tearDown();
    }

    /**
     * Test that preview links are rewritten to point at the React app
     */
    public function test_preview_link_modified_to_react()
    {
        // Create a draft post
        $post_id = $this->factory->post->create([
            'post_title' => 'Preview Test',
            'post_status' => 'draft',
        ]);

        $post = get_post($post_id);

        // Simulate admin context where preview links are generated
        add_filter('is_admin', '__return_true');

        $original_link = 'http://example.test/?p=' . $post_id;
        $modified = apply_filters('preview_post_link', $original_link, $post);

        $this->assertStringContainsString('http://localhost:5173/preview/', $modified);
        $this->assertStringContainsString('/' . $post->post_type . '/', $modified);
    }
}