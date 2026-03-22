<?php

/**
 * Unit tests for Custom Post Types
 */

class CustomPostTypeTest extends WP_UnitTestCase
{

    /**
     * Test that Project post type is registered
     */
    public function test_project_post_type_exists()
    {
        $post_type = get_post_type_object('project');

        $this->assertNotNull($post_type);
        $this->assertEquals('project', $post_type->name);
        $this->assertTrue($post_type->public);
        $this->assertTrue($post_type->show_in_rest);
    }

    /**
     * Test that Testimonial post type is registered
     */
    public function test_testimonial_post_type_exists()
    {
        $post_type = get_post_type_object('testimonial');

        $this->assertNotNull($post_type);
        $this->assertEquals('testimonial', $post_type->name);
        $this->assertTrue($post_type->public);
    }

    /**
     * Test creating a project with ACF fields
     */
    public function test_create_project_with_acf_fields()
    {
        $project_id = wp_insert_post(array(
            'post_title' => 'Test Project',
            'post_type' => 'project',
            'post_status' => 'publish'
        ));

        // Simulate ACF field updates
        update_field('technologies', 'React, WordPress, PHP', $project_id);
        update_field('project_url', 'https://example.com', $project_id);
        update_field('is_featured', true, $project_id);

        // Verify fields were saved
        $technologies = get_field('technologies', $project_id);
        $project_url = get_field('project_url', $project_id);
        $is_featured = get_field('is_featured', $project_id);

        $this->assertEquals('React, WordPress, PHP', $technologies);
        $this->assertEquals('https://example.com', $project_url);
        $this->assertTrue($is_featured);
    }

    /**
     * Test that featured projects query works
     */
    public function test_featured_projects_query()
    {
        // Create featured project
        $featured_id = wp_insert_post(array(
            'post_title' => 'Featured Project',
            'post_type' => 'project',
            'post_status' => 'publish'
        ));
        update_field('is_featured', true, $featured_id);

        // Create non-featured project
        $normal_id = wp_insert_post(array(
            'post_title' => 'Normal Project',
            'post_type' => 'project',
            'post_status' => 'publish'
        ));
        update_field('is_featured', false, $normal_id);

        // Query featured projects
        $featured_query = new WP_Query(array(
            'post_type' => 'project',
            'meta_query' => array(
                array(
                    'key' => 'is_featured',
                    'value' => '1',
                    'compare' => '='
                )
            )
        ));

        $this->assertEquals(1, $featured_query->post_count);
        $this->assertEquals($featured_id, $featured_query->posts[0]->ID);
    }
}