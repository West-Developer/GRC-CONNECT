<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSuperAdminIsNotActing
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->actingContext() !== null) {
            abort(Response::HTTP_CONFLICT, 'Return to the Admin Console first.');
        }

        return $next($request);
    }
}
