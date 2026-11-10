<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ActivateStudentAccount;
use App\Actions\Privacy\RecordPrivacyNoticeAcceptance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\AccountSetupRequest;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Http\JsonResponse;

final class AccountSetupController extends Controller
{
    public function __invoke(
        AccountSetupRequest $request,
        ActivateStudentAccount $activate,
        RecordPrivacyNoticeAcceptance $recordPrivacyNotice,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $activated = $activate->handle(
            $request->validated('email'),
            $request->validated('code'),
            $request->validated('password'),
            $contextFactory->fromRequest($request),
        );

        // The setup page shows the Data Privacy Notice before the student creates a password (ADR 0055).
        if ($request->validated('privacy_notice_version') !== null) {
            $recordPrivacyNotice->agree($activated, $contextFactory->fromRequest($request));
        }

        return response()->json([
            'data' => [
                'type' => 'account-setup',
                'status' => 'active',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
