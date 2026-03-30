<?php

/**
 * Manual test for MinimalDatabaseOptimizer
 * Usage: php scripts/test-database-optimizer.php
 *
 * - Requests a collection (/wp/v2/posts?per_page=1)
 * - Verifies that `headless:refs:post:{id}` contains a cache key
 * - Updates the post to trigger invalidation and verifies the ref is removed
 */

// Boot WordPress
require_once __DIR__ . '/../wp-load.php';

// Helpers
function out($s)
{
    echo $s . PHP_EOL;
}

out('Starting MinimalDatabaseOptimizer manual test');

// Ensure REST functions available
if (! function_exists('rest_do_request')) {
    out('ERROR: REST API not available');
    exit(2);
}

// Find or create a post to test with
$posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1]);
$created = false;
if (empty($posts)) {
    out('No published posts found; inserting temporary post');
    $id = wp_insert_post([
        'post_title' => 'DB Optimizer Test Post',
        'post_content' => 'Temporary post used by test script',
        'post_status' => 'publish',
    ]);
    if (! $id || is_wp_error($id)) {
        out('ERROR: failed to create temporary post');
        exit(3);
    }
    $created = true;
} else {
    $id = $posts[0]->ID;
}

out('Using post ID: ' . $id);

// Issue REST collection request
$req = new WP_REST_Request('GET', '/wp/v2/posts');
$req->set_param('per_page', 1);
$resp = rest_do_request($req);

if ($resp instanceof WP_Error) {
    out('ERROR: REST request failed: ' . $resp->get_error_message());
    exit(4);
}

$data = $resp->get_data();
if (empty($data) || ! is_array($data)) {
    out('ERROR: unexpected response data');
    exit(5);
}

// Check refs for the returned post(s)
$ref_key = 'headless:refs:post:' . (int) $id;
$refs = wp_cache_get($ref_key, 'headless-refs');

out('Reference key: ' . $ref_key);
out('Refs before update: ' . var_export($refs, true));

$found_refs = ! empty($refs) && is_array($refs);
if (! $found_refs) {
    out('FAIL: no refs found for post — the optimizer did not record any cache keys');
    if ($created) {
        wp_delete_post($id, true);
    }
    exit(6);
}

out('OK: refs recorded');

// Update post to trigger invalidation
$old_title = get_the_title($id);
$update_id = wp_update_post(['ID' => $id, 'post_title' => $old_title . ' [test update]']);
if (! $update_id || is_wp_error($update_id)) {
    out('ERROR: failed to update post');
    if ($created) {
        wp_delete_post($id, true);
    }
    exit(7);
}

// Re-check refs
$refs_after = wp_cache_get($ref_key, 'headless-refs');
out('Refs after update: ' . var_export($refs_after, true));

if (empty($refs_after)) {
    out('PASS: refs removed after post update (invalidation successful)');
    $exit = 0;
} else {
    out('FAIL: refs still present after update (invalidation did not remove keys)');
    $exit = 8;
}

// Cleanup temporary post if created
if ($created) {
    wp_delete_post($id, true);
    out('Temporary post deleted');
}

exit($exit);