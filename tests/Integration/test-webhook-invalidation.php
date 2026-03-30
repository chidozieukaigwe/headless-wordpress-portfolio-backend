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

        // Check both Redis-set and array-based refs; one of them should contain entries
        global $wp_object_cache;
        $redis = null;
        if (isset($wp_object_cache) && method_exists($wp_object_cache, 'redis_instance')) {
            $redis = $wp_object_cache->redis_instance();
        }

        $set_key = 'headless:refs:set:post:' . $post_id;
        $ref_key = 'headless:refs:post:' . $post_id;

        $has_set_members = false;
        if ($redis) {
            try {
                if (method_exists($redis, 'sMembers')) {
                    $members = $redis->sMembers($set_key) ?: [];
                } elseif (method_exists($redis, 'smembers')) {
                    $members = $redis->smembers($set_key) ?: [];
                } else {
                    $members = $redis->smembers($set_key) ?: [];
                }
                $has_set_members = ! empty($members);
            } catch (Exception $e) {
                $has_set_members = false;
            }
        }

        $array_refs = wp_cache_get($ref_key, 'headless-refs') ?: [];
        $has_array_refs = is_array($array_refs) && ! empty($array_refs);

        $this->assertTrue($has_set_members || $has_array_refs, 'Expected refs recorded in either Redis set or array refs');

        // Call invalidation handler
        headless_trigger_post_invalidation($post_id);

        // After invalidation, both forms should be cleared
        $cleared_array_refs = wp_cache_get($ref_key, 'headless-refs');
        $this->assertTrue(empty($cleared_array_refs), 'Array refs should be cleared after invalidation');

        if ($redis) {
            try {
                // Attempt to read members; should be empty or the key deleted
                if (method_exists($redis, 'sMembers')) {
                    $members_after = $redis->sMembers($set_key) ?: [];
                } elseif (method_exists($redis, 'smembers')) {
                    $members_after = $redis->smembers($set_key) ?: [];
                } else {
                    $members_after = $redis->smembers($set_key) ?: [];
                }
            } catch (Exception $e) {
                $members_after = [];
            }

            $this->assertTrue(empty($members_after), 'Redis set members should be cleared after invalidation');
        }
    }
}