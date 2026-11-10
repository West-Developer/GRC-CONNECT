<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ActivateStaffAccount;
use App\Actions\Privacy\RecordPrivacyNoticeAcceptance;
use App\Domain\Organization\CollegeCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\StaffAccountSetupRequest;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Http\JsonResponse;

final class StaffAccountSetupController extends Controller
{
    public function __invoke(
        StaffAccountSetupRequest $request,
        ActivateStaffAccount $activate,
        RecordPrivacyNoticeAcceptance $recordPrivacyNotice,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $college = $request->validated('college') ? CollegeCode::tryFrom($request->validated('college')) : null;

        $activated = $activate->handle(
            $request->validated('email'),
            $request->validated('code'),
            $request->validated('password'),
            $request->validated('name'),
            $contextFactory->fromRequest($request),
            $college,
            $request->validated('masters_degree'),
        );

        // The setup page asks for the Data Privacy Notice before it collects the name and college (ADR 0055).
        if ($request->validated('privacy_notice_version') !== null) {
            $recordPrivacyNotice->agree($activated, $contextFactory->fromRequest($request));
        }

        return response()->json([
            'data' => [
                'type' => 'staff-account-setup',
                'status' => 'active',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
