<?php

namespace App\Support\Http;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

final class ApiErrorResponse
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $headers
     */
    public static function make(
        Request $request,
        ApiErrorCode $code,
        string $message,
        int $status,
        array $errors = [],
        array $headers = [],
    ): JsonResponse {
        $requestId = AssignRequestId::getOrCreate($request);
        $headers[AssignRequestId::HEADER] = $requestId;
        $headers['Cache-Control'] ??= 'no-store';

        return response()->json([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'errors' => $errors === [] ? new stdClass : $errors,
                'request_id' => $requestId,
            ],
        ], $status, $headers);
    }
}
