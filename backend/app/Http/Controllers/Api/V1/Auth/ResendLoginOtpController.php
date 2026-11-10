<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\SendLoginOtp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResendLoginOtpRequest;
use App\Http\Resources\Api\V1\LoginOtpChallengeResource;
use App\Models\LoginOtpChallenge;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use App\Support\Auth\LoginOtpChallenges;
use Illuminate\Validation\ValidationException;

/**
 * Rotates a still-live login-OTP challenge: the old token stops working the
 * moment a new one is issued (`LoginOtpChallenges::issue()` replaces any
 * earlier row for the user), so the frontend must always keep only the
 * latest token in hand.
 */
final class ResendLoginOtpController extends Controller
{
    public function __invoke(
        ResendLoginOtpRequest $request,
        LoginOtpChallenges $challenges,
        SendLoginOtp $sendOtp,
        AuditRequestContextFactory $contextFactory,
    ): LoginOtpChallengeResource {
        $existing = $challenges->findByToken((string) $request->validated('challenge_token'));

        if (
            ! $existing instanceof LoginOtpChallenge
            || $existing->expires_at->isPast()
            || ! $existing->user instanceof User
        ) {
            throw ValidationException::withMessages([
                'challenge_token' => 'Your sign-in session has expired. Please sign in again.',
            ]);
        }

        $user = $existing->user;
        $challenge = $sendOtp->handle($user, $contextFactory->fromRequest($request));

        return LoginOtpChallengeResource::make([
            'token' => $challenge['token'],
            'expiresAt' => $challenge['expiresAt'],
            'email' => $user->email,
        ]);
    }
}
