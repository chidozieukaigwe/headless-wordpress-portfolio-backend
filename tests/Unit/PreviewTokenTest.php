<?php

/**
 * Tests preview_token HMAC flow for the preview REST endpoint
 */
class PreviewTokenTest extends WP_Test_REST_TestCase
{
    protected $post_id;

    public function setUp(): void
    {
        parent::setUp();

        // Create a project post
        $this->post_id = $this->factory->post->create(array(
            'post_title' => 'Preview Token Project',
            'post_type' => 'project',
            'post_status' => 'draft'
        ));
    }

    public function test_valid_preview_token_allows_access()
    {
        $secret = 'test_preview_secret_abc123';
        putenv('PREVIEW_SECRET=' . $secret);

        $expires = time() + 600;
        $hmac = hash_hmac('sha256', $this->post_id . '|' . $expires, $secret);
        $token = base64_encode($hmac . ':' . $expires);

        $request = new WP_REST_Request('GET', '/custom/v1/preview/project/' . $this->post_id);
        $request->set_param('preview_token', $token);

        $response = rest_do_request($request);
        $this->assertEquals(200, $response->get_status());
    }

    public function test_expired_preview_token_is_denied()
    {
        $secret = 'test_preview_secret_abc123';
        putenv('PREVIEW_SECRET=' . $secret);

        $expires = time() - 3600; // expired
        $hmac = hash_hmac('sha256', $this->post_id . '|' . $expires, $secret);
        $token = base64_encode($hmac . ':' . $expires);

        $request = new WP_REST_Request('GET', '/custom/v1/preview/project/' . $this->post_id);
        $request->set_param('preview_token', $token);

        $response = rest_do_request($request);
        $this->assertEquals(401, $response->get_status());
    }
}
