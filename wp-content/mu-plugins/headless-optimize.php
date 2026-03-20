<?php

// =============================================
// 🎯 Headless-Specific Optimizations
// =============================================

// Remove unnecessary scripts and styles from admin
add_action('wp_default_scripts', function ($scripts) {
    if (!is_admin()) {
        $scripts->remove('jquery');
        $scripts->remove('wp-embed');
    }
});

// Disable oEmbed
add_action('init', function () {
    remove_action('rest_api_init', 'wp_oembed_register_route');
    remove_filter('rest_pre_serve_request', '_oembed_rest_pre_serve_request');
    remove_filter('oembed_dataparse', 'wp_filter_oembed_result');
    remove_filter('oembed_response_data', 'get_oembed_response_data_rich');
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
});

// Disable RSS feeds
add_action('do_feed', function () {
    wp_die(__('No feeds available.'), '', ['response' => 403]);
}, 1);

// Remove comment feed
add_filter('feed_links_show_comments_feed', '__return_false');
