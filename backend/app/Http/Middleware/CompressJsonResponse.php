<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gzips JSON API responses for clients that accept it (ADR 0029).
 *
 * `php artisan serve` and a plain XAMPP virtual host send JSON uncompressed, and
 * list endpoints (enrollments with their subject rows, sections, curricula) can be
 * hundreds of kilobytes, which matters most over Wi-Fi or a phone connection.
 * Deliberately narrow: only `JsonResponse`, only above `MIN_BYTES`, only when the
 * client sent `Accept-Encoding: gzip`, and never on a response that already
 * carries a `Content-Encoding` (for example one a real web server compresses).
 * Errors and binary bodies (PDFs) are untouched. Switch off with
 * `API_COMPRESS_JSON=false` when a reverse proxy does the compressing instead.
 */
final class CompressJsonResponse
{
    private const MIN_BYTES = 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('performance.compress_api_json', true)
            || ! $response instanceof JsonResponse
            || $response->headers->has('Content-Encoding')
            || ! str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip')
            || ! function_exists('gzencode')) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false || strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        $compressed = gzencode($content, 5);

        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->setVary('Accept-Encoding', false);

        return $response;
    }
}
