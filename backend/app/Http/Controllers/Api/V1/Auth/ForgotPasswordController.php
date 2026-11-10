<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\SendPasswordResetCode;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated. Always returns the identical generic response
 * regardless of whether the email matches an account — the same
 * enumeration-resistance `ResendStudentAccountSetupController` already
 * follows for the same reason (a login-adjacent, unauthenticated,
 * email-only endpoint). Only an already-ACTIVE, human account (never
 * `queue_kiosk` — a shared device credential, not a personal inbox) may
 * self-service a reset; a pending/disabled account goes through Admission,
 * the Registrar, or IT instead.
 */
final class ForgotPasswordController extends Controller
{
    public function __invoke(
        ForgotPasswordRequest $request,
        SendPasswordResetCode $sendResetCode,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $email = (string) $request->validated('email');

        $user = User::query()
            ->where('email', $email)
            ->where('status', UserStatus::Active)
            ->whereNot('role', UserRole::QueueKiosk)
            ->first();

        if ($user instanceof User) {
            $sendResetCode->handle($user, $contextFactory->fromRequest($request));
        }

        return response()->json([
            'data' => [
                'type' => 'forgot-password',
                'status' => 'sent',
                'message' => 'If an account exists for this email, a password reset code has been sent.',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
