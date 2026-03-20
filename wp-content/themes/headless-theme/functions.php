<?php

/**
 * Headless Theme Functions
 * Disables frontend rendering for headless WordPress
 */

// Redirect all frontend requests to React app
function disable_wp_frontend()
{
    // Allow admin area and API requests
    if (is_admin() || strpos($_SERVER['REQUEST_URI'], '/wp-json/') === 0) {
        return;
    }

    // Redirect to React app (update with your React URL)
    $frontend_url = getenv('FRONTEND_APP_URL') ?: 'http://localhost:3000';
    $frontend_url = esc_url_raw($frontend_url);
    $request_uri = $_SERVER['REQUEST_URI'];

    // Don't redirect if it's an API or admin request
    if (
        strpos($request_uri, '/wp-json/') === 0 ||
        strpos($request_uri, '/wp-admin') === 0
    ) {
        return;
    }

    wp_redirect($frontend_url . $request_uri, 301);
    exit;
}
add_action('template_redirect', 'disable_wp_frontend');

// Remove default theme supports
function headless_theme_setup()
{
    remove_theme_support('post-thumbnails');
    remove_theme_support('custom-header');
    remove_theme_support('custom-logo');
}
add_action('after_setup_theme', 'headless_theme_setup');

// Disable frontend styles and scripts
function disable_frontend_assets()
{
    if (!is_admin()) {
        remove_action('wp_head', 'wp_enqueue_scripts', 1);
        remove_action('wp_head', 'wp_print_styles', 8);
        remove_action('wp_head', 'wp_print_head_scripts', 9);
    }
}
add_action('init', 'disable_frontend_assets');
