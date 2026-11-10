<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\CompressJsonResponse;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Gzip for JSON API payloads (ADR 0029). A throwaway route keeps the test
 * independent of any real endpoint's response size.
 */
final class CompressJsonResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(CompressJsonResponse::class)->group(function (): void {
            Route::get('/__test/big-json', fn () => response()->json(['rows' => array_fill(0, 200, ['code' => 'IT101', 'title' => 'Introduction to Computing'])]));
            Route::get('/__test/small-json', fn () => response()->json(['ok' => true]));
            Route::get('/__test/big-text', fn () => response(str_repeat('plain text ', 500), 200, ['Content-Type' => 'text/plain']));
            Route::get('/__test/already-encoded', fn () => response()->json(['rows' => array_fill(0, 200, 'x')])->header('Content-Encoding', 'br'));
        });
    }

    public function test_a_large_json_response_is_gzipped_when_the_client_accepts_it(): void
    {
        $plain = $this->getJson('/__test/big-json');
        $plainBody = $plain->getContent();

        $response = $this->get('/__test/big-json', ['Accept' => 'application/json', 'Accept-Encoding' => 'gzip, deflate, br']);

        $response->assertOk();
        self::assertSame('gzip', $response->headers->get('Content-Encoding'));
        self::assertStringContainsString('Accept-Encoding', (string) $response->headers->get('Vary'));
        $compressed = (string) $response->getContent();
        self::assertLessThan(strlen($plainBody), strlen($compressed), 'the payload should actually be smaller');
        self::assertSame($plainBody, gzdecode($compressed), 'decompressing must give the exact original JSON');
        self::assertSame((string) strlen($compressed), $response->headers->get('Content-Length'));
    }

    public function test_nothing_is_compressed_without_an_accept_encoding_header(): void
    {
        $response = $this->getJson('/__test/big-json');

        self::assertNull($response->headers->get('Content-Encoding'));
        self::assertNotFalse(json_decode((string) $response->getContent()));
    }

    public function test_a_small_json_response_is_left_alone(): void
    {
        $response = $this->get('/__test/small-json', ['Accept' => 'application/json', 'Accept-Encoding' => 'gzip']);

        self::assertNull($response->headers->get('Content-Encoding'));
        $response->assertExactJson(['ok' => true]);
    }

    public function test_a_non_json_response_is_left_alone(): void
    {
        $response = $this->get('/__test/big-text', ['Accept-Encoding' => 'gzip']);

        self::assertNull($response->headers->get('Content-Encoding'));
    }

    public function test_an_already_encoded_response_is_not_compressed_twice(): void
    {
        $response = $this->get('/__test/already-encoded', ['Accept' => 'application/json', 'Accept-Encoding' => 'gzip']);

        self::assertSame('br', $response->headers->get('Content-Encoding'));
    }

    public function test_it_can_be_switched_off_by_config(): void
    {
        config(['performance.compress_api_json' => false]);

        $response = $this->get('/__test/big-json', ['Accept' => 'application/json', 'Accept-Encoding' => 'gzip']);

        self::assertNull($response->headers->get('Content-Encoding'));
    }
}
