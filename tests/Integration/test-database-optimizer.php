<?php

/**
 * Integration test for MinimalDatabaseOptimizer mu-plugin
 *
 * This test runs inside the WordPress PHPUnit integration harness
 * and verifies that:
 *  - a REST collection request records per-post refs
 *  - updating the post triggers targeted invalidation (refs removed)
 */

class Test_Database_Optimizer_Integration extends WP_UnitTestCase
{
    public function test_collection_creates_ref_and_save_invalidates()
    {
        // Create a post to act on
        $post_id = $this->factory->post->create([
            'post_title' => 'DB Optimizer IT Test',
            'post_status' => 'publish',
        ]);

        // Perform a REST collection request; the mu-plugin should cache IDs
        $req = new WP_REST_Request('GET', '/wp/v2/posts');
        $req->set_param('per_page', 1);

        $resp = rest_do_request($req);
        $this->assertNotInstanceOf('WP_Error', $resp, 'REST request failed');
        $this->assertEquals(200, $resp->get_status(), 'Unexpected REST status');

        // Check the per-post refs were recorded
        $ref_key = 'headless:refs:post:' . (int) $post_id;
        $refs = wp_cache_get($ref_key, 'headless-refs');
        $this->assertIsArray($refs, 'Refs should be an array');
        $this->assertNotEmpty($refs, 'Refs should contain at least one cache key');

        // Update the post to trigger invalidation
        wp_update_post(['ID' => $post_id, 'post_title' => 'DB Optimizer IT Test [updated]']);

        // After save_post, refs should be deleted (or empty)
        $refs_after = wp_cache_get($ref_key, 'headless-refs');
        $this->assertTrue(empty($refs_after), 'Refs should be empty after invalidation');
    }
}
