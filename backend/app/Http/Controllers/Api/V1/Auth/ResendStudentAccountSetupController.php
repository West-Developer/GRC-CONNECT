<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Identity\SendStudentAccountSetupInvitation;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResendStudentAccountSetupRequest;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Http\JsonResponse;

final class ResendStudentAccountSetupController extends Controller
{
    public function __invoke(
        ResendStudentAccountSetupRequest $request,
        SendStudentAccountSetupInvitation $sendInvitation,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $email = (string) $request->validated('email');

        $student = User::query()
            ->where('email', $email)
            ->where('role', UserRole::Student)
            ->where('status', UserStatus::Disabled)
            ->whereNull('account_setup_completed_at')
            ->whereNull('archived_at')
            ->first();

        // An unpaid freshman has no account to set up yet; the answer stays the
        // same generic one so this endpoint never reveals who is on file.
        if ($student instanceof User
            && $student->studentProfile !== null
            && ! $student->studentProfile->isFreshmanAwaitingPayment()) {
            $sendInvitation->handle(
                $student->studentProfile,
                $student,
                $contextFactory->fromRequest($request),
            );
        }

        return response()->json([
            'data' => [
                'type' => 'resend-student-account-setup',
                'status' => 'sent',
                'message' => 'If a pending student account exists for this email, a new setup invitation has been sent.',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
