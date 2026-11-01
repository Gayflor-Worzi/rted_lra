<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoginCorsErrorResponseTest extends TestCase
{
    public function test_login_validation_errors_include_cors_for_the_configured_web_origin(): void
    {
        config(['cors.allowed_origins' => ['https://rted-web.onrender.com']]);

        $this->withHeader('Origin', 'https://rted-web.onrender.com')
            ->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertHeader('Access-Control-Allow-Origin', 'https://rted-web.onrender.com')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_login_errors_do_not_reflect_an_unconfigured_origin(): void
    {
        config(['cors.allowed_origins' => ['https://rted-web.onrender.com']]);

        $response = $this->withHeader('Origin', 'https://untrusted.example')
            ->postJson('/api/v1/auth/login', []);

        $response->assertUnprocessable();
        $this->assertNotSame('https://untrusted.example', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
