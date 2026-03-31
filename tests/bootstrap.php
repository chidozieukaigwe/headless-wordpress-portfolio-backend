<?php

/**
 * PHPUnit bootstrap file for WordPress
 *
 * This file sets up the minimal environment required to run unit tests
 * against WordPress in this project. It locates the WordPress PHPUnit
 * test library, loads helper functions, and registers a small function
 * that manually loads the theme and lightweight test doubles used by
 * the unit tests.
 *
 * The important pieces are:
 * - `$_tests_dir`: where the WP test library is located (from `WP_TESTS_DIR` env or /tmp fallback)
 * - requiring `includes/functions.php` from the WP test library to get helpers like `tests_add_filter()`
 * - `_manually_load_environment()` which loads theme code and provides
 *   small stubs for optional plugins (ACF) and registers CPTs used
 *   in unit tests so tests don't depend on plugins being active.
 */

// Determine where the WordPress PHPUnit test library is located.
// CI or the test runner usually provides `WP_TESTS_DIR` pointing to
// `vendor/wp-phpunit/wp-phpunit`. If it's not set, fall back to
// `/tmp/wordpress-tests-lib` (commonly used by older WP test setups).
$_tests_dir = getenv('WP_TESTS_DIR');

if (! $_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

// Export Redis-related constants from environment variables so the
// object-cache drop-in (object-cache.php) picks up the correct host/port
// when running inside Docker test containers. docker-compose.test.yml
// sets WP_REDIS_HOST=redis; define the constants here so object-cache
// uses that value instead of its default 127.0.0.1.
foreach (array('WP_REDIS_HOST', 'WP_REDIS_PORT', 'WP_REDIS_PASSWORD', 'WP_REDIS_DATABASE', 'WP_REDIS_CLIENT') as $env_const) {
    $val = getenv($env_const);
    if ($val !== false && ! defined($env_const)) {
        define($env_const, $val);
    }
}

// Include WP test helpers (provides functions like `tests_add_filter`).
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin/theme being tested
 */
/**
 * Manually load the minimal environment needed for the unit tests.
 *
 * WordPress unit tests normally bootstrap a full WP install. For
 * lightweight unit tests we load the theme under test and provide
 * small, safe fallbacks for optional plugin functions (e.g. ACF) so
 * tests can run without the plugin being installed.
 */
function _manually_load_environment()
{
    // -----------------------------
    // Lightweight ACF function stubs
    // -----------------------------
    // These stubs emulate a tiny portion of ACF's API so tests that
    // call `update_field`, `get_field`, or `get_fields` will work
    // without requiring the Advanced Custom Fields plugin. They use
    // post meta under the hood which is sufficient for most unit tests.
    if (! function_exists('update_field')) {
        function update_field($field_key, $value, $post_id)
        {
            return update_post_meta($post_id, $field_key, $value);
        }
    }

    if (! function_exists('get_field')) {
        function get_field($field_key, $post_id = null)
        {
            if (! $post_id) {
                return null; // ACF returns null for missing context
            }
            $val = get_post_meta($post_id, $field_key, true);
            // Convert '1'/'0' strings to booleans to mirror ACF behavior
            if ($val === '1') {
                return true;
            }
            if ($val === '0') {
                return false;
            }
            return $val;
        }
    }

    if (! function_exists('get_fields')) {
        function get_fields($post_id = null)
        {
            if (! $post_id) {
                return array();
            }
            $meta = get_post_meta($post_id);
            // Flatten single-value meta arrays into scalars for convenience
            $flat = array();
            foreach ($meta as $k => $v) {
                $flat[$k] = is_array($v) && count($v) === 1 ? $v[0] : $v;
            }
            return $flat;
        }
    }

    // ---------------------------------
    // Load the theme being tested (headless-theme)
    // ---------------------------------
    // This ensures any theme functions, filters, or CPT registrations
    // execute before tests run. If your theme registers post types
    // conditionally, registering them here prevents tests from failing
    // due to missing post types.
    require dirname(__DIR__) . '/wp-content/themes/headless-theme/functions.php';

    // ---------------------------------
    // Ensure required custom post types exist
    // ---------------------------------
    // Unit tests expect `project` and `testimonial` CPTs. If the
    // theme doesn't register them in the test environment, register
    // minimal versions here so tests can rely on them.
    if (! post_type_exists('project')) {
        register_post_type('project', array(
            'label' => 'Project',
            'public' => true,
            'show_in_rest' => true,
            'supports' => array('title', 'editor'),
        ));
    }

    if (! post_type_exists('testimonial')) {
        register_post_type('testimonial', array(
            'label' => 'Testimonial',
            'public' => true,
            'show_in_rest' => false,
            'supports' => array('title', 'editor'),
        ));
    }

    // If you need to load local test plugins for specific tests,
    // uncomment and adjust the line below to require them here.
    // require dirname(__DIR__) . '/wp-content/plugins/custom-plugin/custom-plugin.php';
}
// Hook our manual loader into the WP test bootstrap sequence. The
// WP PHPUnit bootstrap will call this during initialization so the
// theme and our stubs are available to tests.
tests_add_filter('muplugins_loaded', '_manually_load_environment');

// Finally, start the WP testing environment using the test library
// located at `$_tests_dir`. This call will bring up a lightweight
// WP installation in memory suitable for unit tests.
require $_tests_dir . '/includes/bootstrap.php';