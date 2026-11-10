<?php

use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CompressJsonResponse;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Support\Http\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(CompressJsonResponse::class);
        $middleware->append(ApplySecurityHeaders::class);
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'super_admin' => EnsureUserIsSuperAdmin::class,
        ]);

        // This is a JSON-only API with no `login` named route. Laravel's
        // ApplicationBuilder unconditionally defaults unauthenticated guests
        // to `redirectGuestsTo(fn () => route('login'))`; without this
        // override, any request that omits `Accept: application/json` (i.e.
        // does not satisfy Request::expectsJson()) crashes with a 500
        // RouteNotFoundException instead of the documented 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*'),
        );

        $exceptions->render(
            fn (Throwable $exception, Request $request) => ApiExceptionRenderer::render($exception, $request),
        );
    })->create();
