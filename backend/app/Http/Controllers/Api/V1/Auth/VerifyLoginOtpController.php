<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\VerifyLoginOtp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\VerifyLoginOtpRequest;
use App\Http\Resources\Api\V1\AuthResource;
use App\Support\Audit\AuditRequestContextFactory;

final class VerifyLoginOtpController extends Controller
{
    public function __invoke(
        VerifyLoginOtpRequest $request,
        VerifyLoginOtp $verifyOtp,
        AuditRequestContextFactory $contextFactory,
    ): AuthResource {
        $session = $verifyOtp->handle(
            $request->validated('challenge_token'),
            $request->validated('code'),
            $contextFactory->fromRequest($request),
        );

        return AuthResource::make($session);
    }
}
