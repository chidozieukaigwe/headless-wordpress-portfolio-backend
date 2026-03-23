<?php

/**
 * Tests that block parsing and optional server-side rendering are exposed
 * on REST responses for `project` post type.
 */
class BlocksRestResponseTest extends WP_UnitTestCase
{
    public function test_blocks_and_rendered_blocks_present_in_rest_response()
    {
        // Create a post with a simple paragraph block
        $content = "<!-- wp:paragraph --><p>Hello from block</p><!-- /wp:paragraph -->";

        $post_id = wp_insert_post(array(
            'post_title' => 'Blocky Project',
            'post_type' => 'project',
            'post_status' => 'publish',
            'post_content' => $content,
        ));

        $request = new WP_REST_Request('GET', '/wp/v2/project/' . $post_id);
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertArrayHasKey('blocks', $data);
        $this->assertIsArray($data['blocks']);

        // `rendered_blocks` may be present if render_block exists; ensure key exists
        $this->assertArrayHasKey('rendered_blocks', $data);
        $this->assertIsArray($data['rendered_blocks']);

        // If render_block is available, at least one rendered block should be a string
        if (function_exists('render_block')) {
            $this->assertNotEmpty($data['rendered_blocks']);
            $this->assertIsString($data['rendered_blocks'][0]);
        }
    }
}
