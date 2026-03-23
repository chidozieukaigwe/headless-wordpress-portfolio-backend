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

/**
 * Custom endpoint to get preview data for any post type
 * GET /wp-json/custom/v1/preview/{post_type}/{id}
 */
function register_preview_endpoint()
{
    // Use `custom/v1` namespace so endpoints are available at /wp-json/custom/v1/...
    register_rest_route('custom/v1', '/preview/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'get_preview_content',
        'permission_callback' => 'verify_preview_permission',
        'args' => array(
            'post_type' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return in_array($param, ['post', 'page', 'project']);
                }
            ),
            'id' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param);
                }
            )
        )
    ));
}
add_action('rest_api_init', 'register_preview_endpoint');

/**
 * Verify user has permission to preview
 */
function verify_preview_permission($request)
{
    $post_id = $request->get_param('id');

    // Accept a signed preview token for cross-origin previews
    $preview_token = $request->get_param('preview_token');
    if ($preview_token) {
        $secret = getenv('PREVIEW_SECRET') ?: (defined('AUTH_KEY') ? AUTH_KEY : '');
        if ($secret === '') {
            return new WP_Error('rest_forbidden', 'Preview token not configured', array('status' => 401));
        }

        $decoded = base64_decode($preview_token, true);
        if ($decoded !== false) {
            list($hmac, $expires) = array_pad(explode(':', $decoded, 2), 2, null);
            if ($hmac && $expires && is_numeric($expires)) {
                if ((int) $expires >= time()) {
                    $expected = hash_hmac('sha256', $post_id . '|' . $expires, $secret);
                    if (hash_equals($expected, $hmac)) {
                        return true;
                    }
                }
            }
        }

        return new WP_Error('rest_forbidden', 'Invalid or expired preview token', array('status' => 401));
    }

    // Allow if user is logged in and can edit this post
    if (is_user_logged_in()) {
        return current_user_can('edit_post', $post_id);
    }

    // Accept header 'X-WP-Nonce' or 'X-Preview-Nonce', or query param '_wpnonce'
    $nonce = $request->get_header('X-WP-Nonce')
        ?: $request->get_header('X-Preview-Nonce')
        ?: $request->get_param('_wpnonce');

    if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
        return true;
    }

    return new WP_Error('rest_forbidden', 'Preview access denied', array('status' => 401));
}

/**
 * Get preview content for any post type
 */
function get_preview_content($request)
{
    $post_type = $request->get_param('post_type');
    $post_id = $request->get_param('id');

    // Get the post, even if it's draft/pending
    $post = get_post($post_id);

    if (!$post || $post->post_type !== $post_type) {
        return new WP_Error('not_found', 'Post not found', array('status' => 404));
    }

    // Apply the_content filter to process shortcodes and blocks
    $content = apply_filters('the_content', $post->post_content);

    // Get featured image
    $featured_image_id = get_post_thumbnail_id($post_id);
    $featured_image = null;
    if ($featured_image_id) {
        $featured_image = [
            'id' => $featured_image_id,
            'url' => wp_get_attachment_url($featured_image_id),
            'sizes' => [
                'thumbnail' => wp_get_attachment_image_url($featured_image_id, 'thumbnail'),
                'medium' => wp_get_attachment_image_url($featured_image_id, 'medium'),
                'large' => wp_get_attachment_image_url($featured_image_id, 'large'),
                'full' => wp_get_attachment_image_url($featured_image_id, 'full')
            ]
        ];
    }

    // Get ACF fields
    $acf_fields = get_fields($post_id);

    // Prepare response
    $preview_data = array(
        'id' => $post->ID,
        'title' => $post->post_title,
        'content' => $content,
        'excerpt' => $post->post_excerpt,
        'slug' => $post->post_name,
        'status' => $post->post_status,
        'date' => $post->post_date,
        'modified' => $post->post_modified,
        'featured_media' => $featured_image,
        'acf' => $acf_fields ? $acf_fields : array(),
        '_preview' => true, // Flag to indicate this is preview content
        '_preview_nonce' => wp_create_nonce('wp_rest')
    );

    // Add custom post type specific data
    if ($post_type === 'project') {
        $preview_data['project_url'] = get_post_meta($post_id, 'project_url', true);
        $preview_data['technologies'] = get_post_meta($post_id, 'technologies', true);
    }

    return rest_ensure_response($preview_data);
}

/**
 * Modify preview link in WordPress admin to point to React app
 */
function modify_preview_link($preview_link, $post)
{
    $react_app_url = getenv('FRONTEND_APP_URL') ?: 'http://localhost:3000';
    $react_app_url = esc_url_raw($react_app_url);

    // Only modify the preview link when in the admin (where preview links are generated)
    if (! is_admin()) {
        return $preview_link;
    }

    // Create preview URL for React app
    $preview_url = $react_app_url . '/preview/' . $post->post_type . '/' . $post->ID;

    // Add preview nonce for authentication
    $nonce = wp_create_nonce('wp_rest');
    $preview_url = add_query_arg('_wpnonce', $nonce, $preview_url);

    // Add a signed preview token so the React app can request preview content
    $secret = getenv('PREVIEW_SECRET') ?: (defined('AUTH_KEY') ? AUTH_KEY : '');
    if ($secret) {
        $expires = time() + 600; // 10 minute expiry
        $hmac = hash_hmac('sha256', $post->ID . '|' . $expires, $secret);
        $token = base64_encode($hmac . ':' . $expires);
        $preview_url = add_query_arg('preview_token', $token, $preview_url);
    }

    return $preview_url;
}
add_filter('preview_post_link', 'modify_preview_link', 10, 2);

/**
 * Expand ACF image fields in REST responses for `project` post type.
 * Adds a `{field_name}_url` entry for any ACF image fields that return an ID.
 */
function expand_acf_image_fields_in_rest($response, $post, $request)
{
    if (! function_exists('get_fields')) {
        return $response;
    }

    $data = $response->get_data();
    $acf = get_fields($post->ID);
    if (! $acf || ! is_array($acf)) {
        return $response;
    }

    foreach ($acf as $key => $value) {
        // If ACF's `get_field_object` helper is available, use it to
        // detect image fields and expand IDs to URLs. If it's not
        // available in the test environment, skip expansion but keep
        // the raw ACF values so tests still receive an `acf` key.
        if (function_exists('get_field_object')) {
            $field_obj = get_field_object($key, $post->ID);
            if (! $field_obj || empty($field_obj['type'])) {
                continue;
            }

            if ($field_obj['type'] === 'image') {
                // If ACF is returning an ID, convert to URL and expose as separate key.
                if (is_numeric($value)) {
                    $url = wp_get_attachment_image_url((int) $value, 'full');
                    if ($url) {
                        $acf[$key . '_url'] = $url;
                    }
                } elseif (is_array($value) && isset($value['ID'])) {
                    $url = wp_get_attachment_image_url((int) $value['ID'], 'full');
                    if ($url) {
                        $acf[$key . '_url'] = $url;
                    }
                }
            }
        }
    }

    $data['acf'] = $acf;
    $response->set_data($data);

    return $response;
}

add_filter('rest_prepare_project', 'expand_acf_image_fields_in_rest', 10, 3);

/**
 * Add parsed Gutenberg blocks and optional server-rendered HTML blocks
 * to REST responses for `project` post type so headless frontends can
 * consume block data or pre-rendered HTML.
 */
function add_blocks_to_rest_response($response, $post, $request)
{
    $data = $response->get_data();

    // Parse blocks (raw block structure)
    if (function_exists('parse_blocks')) {
        $blocks = parse_blocks($post->post_content);
        $data['blocks'] = $blocks;
    } else {
        $data['blocks'] = array();
    }

    // Optionally include server-rendered HTML for each block. This is
    // useful for blocks that rely on server-side rendering (dynamic blocks).
    if (function_exists('render_block')) {
        $rendered = array();
        foreach ($data['blocks'] as $block) {
            // render_block accepts a block array and returns HTML
            try {
                $rendered[] = render_block($block);
            } catch (Throwable $e) {
                // Fallback: use innerHTML if render fails
                $rendered[] = isset($block['innerHTML']) ? $block['innerHTML'] : '';
            }
        }
        $data['rendered_blocks'] = $rendered;
    }

    $response->set_data($data);
    return $response;
}

add_filter('rest_prepare_project', 'add_blocks_to_rest_response', 20, 3);
