<?php

namespace Tests\Feature\Api\V1;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_health_endpoint_returns_the_exact_resource_envelope_and_headers(): void
    {
        Carbon::setTestNow('2026-07-26T08:30:00Z');
        $requestId = 'frontend-health-check-001';

        $response = $this
            ->withHeader(AssignRequestId::HEADER, $requestId)
            ->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader(AssignRequestId::HEADER, $requestId)
            ->assertExactJson([
                'data' => [
                    'type' => 'service-health',
                    'service' => 'grc-enrollment-api',
                    'status' => 'ok',
                    'api_version' => 'v1',
                    'generated_at' => '2026-07-26T08:30:00Z',
                ],
            ]);

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
    }

    public function test_health_endpoint_generates_a_request_id_when_one_is_not_supplied(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();

        $requestId = $response->headers->get(AssignRequestId::HEADER);

        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $requestId,
        );
    }

    public function test_health_endpoint_replaces_an_invalid_request_id(): void
    {
        $response = $this
            ->withHeader(AssignRequestId::HEADER, 'unsafe:request:id')
            ->getJson('/api/v1/health');

        $response->assertOk();

        $requestId = $response->headers->get(AssignRequestId::HEADER);

        $this->assertIsString($requestId);
        $this->assertNotSame('unsafe:request:id', $requestId);
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $requestId,
        );
    }

    public function test_health_endpoint_preserves_every_documented_request_id_character(): void
    {
        $requestId = '._-frontend-health-check';

        $response = $this
            ->withHeader(AssignRequestId::HEADER, $requestId)
            ->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertHeader(AssignRequestId::HEADER, $requestId);
    }

    public function test_health_endpoint_exposes_only_the_configured_frontend_origin(): void
    {
        config([
            'cors.allowed_origins' => [
                'http://localhost:5173',
                'http://127.0.0.1:5173',
            ],
        ]);

        $allowedResponse = $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/v1/health');

        $allowedResponse
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $loopbackResponse = $this
            ->withHeader('Origin', 'http://127.0.0.1:5173')
            ->getJson('/api/v1/health');

        $loopbackResponse
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5173')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $blockedResponse = $this
            ->withHeader('Origin', 'https://unapproved.example')
            ->getJson('/api/v1/health');

        $blockedResponse
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_health_endpoint_accepts_the_documented_cors_preflight(): void
    {
        $response = $this
            ->withHeaders([
                'Origin' => 'http://localhost:5173',
                'Access-Control-Request-Method' => 'GET',
                'Access-Control-Request-Headers' => 'X-Request-ID',
            ])
            ->options('/api/v1/health');

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Methods')
            ->assertHeader('Access-Control-Allow-Headers')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_queue_ticket_claim_accepts_the_real_kiosk_cors_preflight_headers(): void
    {
        $response = $this
            ->withHeaders([
                'Origin' => 'http://localhost:5173',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'Authorization, Content-Type, X-Queue-Kiosk-Token',
            ])
            ->options('/api/v1/queue-tickets');

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Methods')
            ->assertHeader('Access-Control-Allow-Headers')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $allowedHeaders = array_map(
            static fn (string $header): string => strtolower(trim($header)),
            explode(',', (string) $response->headers->get('Access-Control-Allow-Headers')),
        );

        foreach (['authorization', 'content-type', 'x-queue-kiosk-token'] as $requiredHeader) {
            self::assertContains($requiredHeader, $allowedHeaders);
        }
    }

    public function test_health_endpoint_enforces_the_documented_rate_limit(): void
    {
        $response = null;

        for ($attempt = 0; $attempt < 61; $attempt++) {
            $response = $this->getJson('/api/v1/health');
        }

        $this->assertNotNull($response);

        $requestId = $response->headers->get(AssignRequestId::HEADER);

        $this->assertIsString($requestId);
        $response
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader(AssignRequestId::HEADER, $requestId)
            ->assertExactJson([
                'error' => [
                    'code' => 'THROTTLED',
                    'message' => 'Too many requests. Please retry later.',
                    'errors' => [],
                    'request_id' => $requestId,
                ],
            ]);

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
    }
}
