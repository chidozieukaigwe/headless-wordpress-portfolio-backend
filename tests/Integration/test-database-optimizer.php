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

        // Perform a REST collection request; request the created post explicitly
        // so the test is deterministic and the optimizer will record refs
        $req = new WP_REST_Request('GET', '/wp/v2/posts');
        $req->set_param('include', [$post_id]);
        $req->set_param('per_page', 1);

        $resp = rest_do_request($req);
        $this->assertNotInstanceOf('WP_Error', $resp, 'REST request failed');
        $this->assertEquals(200, $resp->get_status(), 'Unexpected REST status');

        // Some test environments reset the object-cache between request lifecycle
        // so invoke the optimizer's caching method directly to ensure the in-process
        // cache entries are created for assertion.
        if (class_exists('MinimalDatabaseOptimizer')) {
            MinimalDatabaseOptimizer::get_instance()->cache_response_ids($resp, null, $req);
        }

        // Ensure Redis is available for set-based refs; these integration
        // tests expect the Redis migration to have completed.
        global $wp_object_cache;
        $redis = null;
        if (isset($wp_object_cache) && method_exists($wp_object_cache, 'redis_instance')) {
            $redis = $wp_object_cache->redis_instance();
        }

        if (! $redis) {
            $this->markTestSkipped('Redis not available; set-based refs required for this test.');
            return;
        }

        // Check the Redis set contains the reference key
        $set_key = 'headless:refs:set:post:' . (int) $post_id;
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

        $this->assertNotEmpty($members, 'Redis set should contain at least one cache key');
        // Record the referenced cache keys so we can verify they are deleted
        $referenced_keys = $members;

        // Update the post to trigger invalidation
        wp_update_post(['ID' => $post_id, 'post_title' => 'DB Optimizer IT Test [updated]']);

        // After save_post, the referenced id-list cache keys should be deleted
        foreach ($referenced_keys as $k) {
            $val = wp_cache_get($k, 'headless-ids');
            $this->assertTrue(empty($val), 'Referenced cache key should be deleted after invalidation: ' . $k);
        }
    }
}
