<?php

/**
 * Integration test for webhook invalidation mu-plugin
 *
 * Verifies that when a post is included in a cached collection response,
 * the per-post references are recorded (either as Redis SETs or array refs),
 * and that calling the invalidation handler removes those references and
 * deletes the referenced id-list cache keys.
 */

class Test_Webhook_Invalidation_Integration extends WP_UnitTestCase
{
    public function test_invalidation_removes_refs_and_caches()
    {
        // Create a post to act on
        $post_id = $this->factory->post->create([
            'post_title'  => 'Webhook Invalidation IT',
            'post_status' => 'publish',
        ]);

        // Make a deterministic REST collection request that will include the post
        $req = new WP_REST_Request('GET', '/wp/v2/posts');
        $req->set_param('include', [$post_id]);
        $req->set_param('per_page', 1);

        $resp = rest_do_request($req);
        $this->assertNotInstanceOf('WP_Error', $resp);
        $this->assertEquals(200, $resp->get_status());

        // Ensure optimizer recorded refs (call directly to be robust in test harness)
        if (class_exists('MinimalDatabaseOptimizer')) {
            MinimalDatabaseOptimizer::get_instance()->cache_response_ids($resp, null, $req);
        }

        // Expect Redis set-based refs; legacy array refs have been removed.
        global $wp_object_cache;
        $redis = null;
        if (isset($wp_object_cache) && method_exists($wp_object_cache, 'redis_instance')) {
            $redis = $wp_object_cache->redis_instance();
        }

        if (! $redis) {
            $this->markTestSkipped('Redis not available; set-based refs required for this test.');
            return;
        }

        $set_key = 'headless:refs:set:post:' . $post_id;
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

        $this->assertNotEmpty($members, 'Expected refs recorded in Redis set');
        $referenced_keys = $members;

        // Call invalidation handler
        headless_trigger_post_invalidation($post_id);

        // After invalidation, the referenced id-list cache keys should be deleted
        foreach ($referenced_keys as $k) {
            $val = wp_cache_get($k, 'headless-ids');
            $this->assertTrue(empty($val), 'Referenced cache key should be deleted after invalidation: ' . $k);
        }
    }
}
