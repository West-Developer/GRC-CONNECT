<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateWithGoogle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\GoogleLoginRequest;
use App\Http\Resources\Api\V1\AuthResource;
use App\Support\Audit\AuditRequestContextFactory;

final class GoogleLoginController extends Controller
{
    public function __invoke(
        GoogleLoginRequest $request,
        AuthenticateWithGoogle $authenticate,
        AuditRequestContextFactory $contextFactory,
    ): AuthResource {
        $session = $authenticate->handle(
            $request->validated('credential'),
            $contextFactory->fromRequest($request),
        );

        return AuthResource::make($session);
    }
}
