<?php

/**
 * Tests ACF image field expansion in REST responses
 */
class AcfImageExpansionTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // ensure ACF stubs are available from bootstrap; define get_field_object
        if (! function_exists('get_field_object')) {
            function get_field_object($key, $post_id = null)
            {
                return array('type' => 'image');
            }
        }
    }

    public function test_acf_image_field_is_expanded_to_url_in_rest()
    {
        // Create an attachment (image)
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'image/jpeg',
            'post_title' => 'Test Image',
            'post_content' => '',
            'post_status' => 'inherit',
            'guid' => 'http://example.test/wp-content/uploads/test-image.jpg'
        ));

        // Create project post
        $post_id = wp_insert_post(array(
            'post_title' => 'Project with Image',
            'post_type' => 'project',
            'post_status' => 'publish'
        ));

        // Set ACF image field to the attachment ID
        update_field('gallery_image', $attachment_id, $post_id);

        // Request the REST endpoint for the single project
        $request = new WP_REST_Request('GET', '/wp/v2/project/' . $post_id);
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertArrayHasKey('acf', $data);
        $this->assertArrayHasKey('gallery_image', $data['acf']);
        $this->assertArrayHasKey('gallery_image_url', $data['acf']);

        $expected_url = wp_get_attachment_image_url($attachment_id, 'full') ?: wp_get_attachment_url($attachment_id);
        $this->assertEquals($expected_url, $data['acf']['gallery_image_url']);
    }
}
