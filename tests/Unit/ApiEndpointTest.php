<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for custom REST API endpoints
 *
 * Notes:
 * - `WP_Test_REST_TestCase` is provided by the WordPress PHPUnit test suite
 *   (the WP test bootstrap that comes from `wp-phpunit` / WordPress core tests).
 *   Run these tests via the project's PHPUnit configuration so the WP test
 *   environment is bootstrapped (for example: `vendor/bin/phpunit`).
 *
 * @property WP_UnitTest_Factory $factory Provided by WP_UnitTestCase
 */
class ApiEndpointTest extends WP_Test_REST_TestCase
{
    /**
     * ID of the test user created in setUp
     * @var int
     */
    protected $user_id;

    /**
     * ID of the test project post created in setUp
     * @var int
     */
    protected $project_id;

    public function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user_id = $this->factory->user->create(array(
            'role' => 'administrator'
        ));

        // Create test project
        $this->project_id = $this->factory->post->create(array(
            'post_title' => 'Test API Project',
            'post_type' => 'project',
            'post_status' => 'publish'
        ));

        // Add ACF fields
        update_field('technologies', 'React, WordPress', $this->project_id);
    }

    /**
     * Test that preview endpoint requires authentication
     */
    public function test_preview_endpoint_requires_auth()
    {
        $request = new WP_REST_Request('GET', '/custom/v1/preview/project/' . $this->project_id);
        $response = rest_do_request($request);

        $this->assertEquals(401, $response->get_status());
    }

    /**
     * Test that preview endpoint works with valid nonce
     */
    public function test_preview_endpoint_with_valid_nonce()
    {
        wp_set_current_user($this->user_id);

        $nonce = wp_create_nonce('wp_rest');

        $request = new WP_REST_Request('GET', '/custom/v1/preview/project/' . $this->project_id);
        $request->set_header('X-Preview-Nonce', $nonce);

        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertEquals('Test API Project', $data['title']);
        $this->assertArrayHasKey('acf', $data);
        $this->assertEquals('React, WordPress', $data['acf']['technologies']);
    }

    /**
     * Test that projects endpoint returns correct data
     */
    public function test_projects_endpoint_structure()
    {
        $request = new WP_REST_Request('GET', '/wp/v2/project');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);

        $first_project = $data[0];
        $this->assertArrayHasKey('id', $first_project);
        $this->assertArrayHasKey('title', $first_project);
        $this->assertArrayHasKey('acf', $first_project);
    }
}