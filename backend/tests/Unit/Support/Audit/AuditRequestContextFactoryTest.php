<?php

namespace Tests\Unit\Support\Audit;

use App\Http\Middleware\AssignRequestId;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Http\Request;
use Tests\TestCase;

final class AuditRequestContextFactoryTest extends TestCase
{
    public function test_it_retains_a_valid_incoming_request_id_and_ipv4_address(): void
    {
        $request = $this->request('request-123', '203.0.113.18');

        $context = (new AuditRequestContextFactory)->fromRequest($request);

        self::assertSame('request-123', $context->requestId);
        self::assertSame('203.0.113.18', $context->ipAddress);
        self::assertSame('request-123', $request->attributes->get(AssignRequestId::ATTRIBUTE));
    }

    public function test_it_replaces_an_invalid_request_id_and_stores_the_generated_value_on_the_request(): void
    {
        $request = $this->request('unsafe:request:id', '203.0.113.18');

        $context = (new AuditRequestContextFactory)->fromRequest($request);

        self::assertNotSame('unsafe:request:id', $context->requestId);
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $context->requestId,
        );
        self::assertSame($context->requestId, $request->attributes->get(AssignRequestId::ATTRIBUTE));
    }

    public function test_it_captures_an_ipv6_address(): void
    {
        $context = (new AuditRequestContextFactory)->fromRequest($this->request('request-ipv6', '2001:db8::7'));

        self::assertSame('2001:db8::7', $context->ipAddress);
    }

    public function test_it_allows_a_missing_client_ip_address(): void
    {
        $request = $this->request('request-no-ip', null);

        $context = (new AuditRequestContextFactory)->fromRequest($request);

        self::assertNull($context->ipAddress);
    }

    private function request(string $requestId, ?string $ipAddress): Request
    {
        $request = Request::create('/', 'POST', [], [], [], [
            'HTTP_X_REQUEST_ID' => $requestId,
        ]);

        if ($ipAddress !== null) {
            $request->server->set('REMOTE_ADDR', $ipAddress);
        } else {
            $request->server->remove('REMOTE_ADDR');
        }

        return $request;
    }
}
