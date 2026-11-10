<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

final class LogoutController extends Controller
{
    /**
     * Revokes only the token presented on this request, leaving the user's
     * other sessions on other devices intact.
     */
    public function __invoke(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent()
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
