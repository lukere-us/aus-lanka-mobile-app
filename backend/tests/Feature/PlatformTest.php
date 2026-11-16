<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_ok_with_request_id_and_security_headers(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.database', 'ok')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_incoming_request_id_is_echoed_back(): void
    {
        $this->getJson('/api/v1/health', ['X-Request-Id' => 'mobile-abc-12345'])
            ->assertHeader('X-Request-Id', 'mobile-abc-12345');
    }

    public function test_unknown_route_uses_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist', ['X-Request-Id' => 'trace-000000001']);

        $response->assertNotFound()
            ->assertExactJson([
                'error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.'],
                'request_id' => 'trace-000000001',
            ]);
    }

    public function test_cors_preflight_allows_configured_origin_only(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000']]);

        $this->call('OPTIONS', '/api/v1/health', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');

        $evil = $this->call('OPTIONS', '/api/v1/health', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertNull($evil->headers->get('Access-Control-Allow-Origin'));
    }
}
