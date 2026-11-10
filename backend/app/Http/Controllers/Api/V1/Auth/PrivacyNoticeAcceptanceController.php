<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Privacy\RecordPrivacyNoticeAcceptance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\AcceptPrivacyNoticeRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;

/**
 * The signed-in person agrees to the current Data Privacy Notice (ADR 0055). Returns the same user payload as
 * `GET /auth/me`, so the portal updates its session from the answer.
 */
final class PrivacyNoticeAcceptanceController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function store(
        AcceptPrivacyNoticeRequest $request,
        RecordPrivacyNoticeAcceptance $record,
        AuditRequestContextFactory $contextFactory,
    ): UserResource {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $record->agree($user, $contextFactory->fromRequest($request));

        return UserResource::make($user);
    }
}
