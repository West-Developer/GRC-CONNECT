<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ResetPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Http\JsonResponse;

final class ResetPasswordController extends Controller
{
    public function __invoke(
        ResetPasswordRequest $request,
        ResetPassword $resetPassword,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $resetPassword->handle(
            $request->validated('email'),
            $request->validated('code'),
            $request->validated('password'),
            $contextFactory->fromRequest($request),
        );

        return response()->json([
            'data' => [
                'type' => 'reset-password',
                'status' => 'reset',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
