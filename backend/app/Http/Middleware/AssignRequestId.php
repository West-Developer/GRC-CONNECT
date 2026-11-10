<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public const ATTRIBUTE = 'request_id';

    public const HEADER = 'X-Request-ID';

    private const VALID_REQUEST_ID = '/\A[A-Za-z0-9._-]{1,128}\z/D';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = self::getOrCreate($request);
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    public static function getOrCreate(Request $request): string
    {
        $requestId = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($requestId)) {
            return $requestId;
        }

        $candidate = $request->headers->get(self::HEADER);
        $requestId = is_string($candidate) && preg_match(self::VALID_REQUEST_ID, $candidate) === 1
            ? $candidate
            : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        return $requestId;
    }
}
