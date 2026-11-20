<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_responses_carry_security_headers(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=()'
        );
    }

    public function test_error_responses_carry_security_headers(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertUnprocessable();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_api_responses_carry_a_content_security_policy(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized();
        $response->assertHeader(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'"
        );
    }

    public function test_hsts_is_sent_when_enabled(): void
    {
        config(['security.hsts.enabled' => true]);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertHeader(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains'
        );
    }

    public function test_hsts_is_not_sent_by_default_over_plain_http(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }
}
