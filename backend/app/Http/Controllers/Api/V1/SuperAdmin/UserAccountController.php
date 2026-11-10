<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Auth\SendPasswordResetCode;
use App\Actions\Identity\InviteStaffAccount;
use App\Actions\Identity\SendStaffAccountSetupInvitation;
use App\Actions\Identity\SendStudentAccountSetupInvitation;
use App\Actions\SuperAdmin\ChangeUserRole;
use App\Actions\SuperAdmin\DeleteUnusedUserAccount;
use App\Actions\SuperAdmin\ListManagedUsers;
use App\Actions\SuperAdmin\SetUserAccountStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditableType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\ChangeUserRoleRequest;
use App\Http\Requests\Api\V1\SuperAdmin\InviteUserAccountRequest;
use App\Http\Requests\Api\V1\SuperAdmin\SetUserAccountStatusRequest;
use App\Http\Resources\Api\V1\SuperAdmin\UserAccountResource;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class UserAccountController extends Controller
{
    public function index(
        Request $request,
        ListManagedUsers $action,
        AuditRequestContextFactory $contextFactory,
    ): AnonymousResourceCollection {
        Gate::authorize('view-any-user-account');

        $actor = $request->user();
        $context = $contextFactory->fromRequest($request);

        $search = $request->query('q');
        $roleStr = $request->query('role');
        $role = $roleStr !== null ? UserRole::tryFrom($roleStr) : null;
        $statusStr = $request->query('status');
        $status = $statusStr !== null ? UserStatus::tryFrom($statusStr) : null;
        $collegeStr = $request->query('college');
        $college = $collegeStr !== null ? CollegeCode::tryFrom($collegeStr) : null;

        $pendingSetup = null;
        if ($request->has('pending_setup')) {
            $pendingSetup = filter_var($request->query('pending_setup'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $users = $action->execute(
            $actor,
            $context,
            $search,
            $role,
            $status,
            $college,
            $pendingSetup,
            $perPage,
        );

        return UserAccountResource::collection($users);
    }

    public function invite(
        InviteUserAccountRequest $request,
        InviteStaffAccount $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        Gate::authorize('invite-user-account');

        $role = UserRole::from($request->validated('role'));
        $college = $request->validated('college') ? CollegeCode::from($request->validated('college')) : null;

        $user = $action->handle(
            $request->validated('email'),
            $role,
            $request->user(),
            $contextFactory->fromRequest($request),
            $college,
            $request->validated('masters_degree'),
            UserRole::superAdminInvitableCases(),
        );

        return (new UserAccountResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function changeRole(
        ChangeUserRoleRequest $request,
        User $user,
        ChangeUserRole $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        Gate::authorize('change-user-account-role', $user);

        $role = UserRole::from($request->validated('role'));
        $college = $request->validated('college') ? CollegeCode::from($request->validated('college')) : null;

        $updated = $action->execute(
            $user,
            $role,
            $college,
            $request->validated('reason'),
            $request->user(),
            $contextFactory->fromRequest($request),
        );

        return (new UserAccountResource($updated))->response();
    }

    public function updateStatus(
        SetUserAccountStatusRequest $request,
        User $user,
        SetUserAccountStatus $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        Gate::authorize('update-user-account-status', $user);

        $status = UserStatus::from($request->validated('status'));

        $updated = $action->execute(
            $user,
            $status,
            $request->validated('reason'),
            $request->user(),
            $contextFactory->fromRequest($request),
        );

        return (new UserAccountResource($updated))->response();
    }

    public function resendSetupInvitation(
        Request $request,
        User $user,
        SendStaffAccountSetupInvitation $sendStaffInvitation,
        SendStudentAccountSetupInvitation $sendStudentInvitation,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        Gate::authorize('resend-user-account-invitation', $user);

        $context = $contextFactory->fromRequest($request);

        if ($user->role === UserRole::Student) {
            $studentProfile = $user->studentProfile;
            if (! $studentProfile instanceof StudentProfile) {
                throw ValidationException::withMessages([
                    'user' => ['Student profile not found for this user.'],
                ]);
            }
            $sendStudentInvitation->handle($studentProfile, $request->user(), $context);
        } else {
            $sendStaffInvitation->handle($user, $request->user(), $context);
        }

        return (new UserAccountResource($user->refresh()))->response();
    }

    public function sendPasswordReset(
        Request $request,
        User $user,
        SendPasswordResetCode $action,
        AuditRecorder $auditRecorder,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        Gate::authorize('reset-user-account-password', $user);

        $throttleKey = 'super-admin-password-reset|'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw new TooManyRequestsHttpException(
                RateLimiter::availableIn($throttleKey),
                'Too many password reset attempts.',
            );
        }
        RateLimiter::hit($throttleKey, 60);

        if ($user->status !== UserStatus::Active || $user->role === UserRole::QueueKiosk || $user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'user' => ['Only an active, non-kiosk account can receive a password reset code.'],
            ]);
        }

        $context = $contextFactory->fromRequest($request);
        $result = $action->handle($user, $context);

        $auditRecorder->record(
            $request->user(),
            AuditAction::USER_ACCOUNT_PASSWORD_RESET_SENT,
            AuditableType::USER_ACCOUNT,
            $user->id,
            null,
            ['delivery_status' => $result],
            null,
            $context,
        );

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'status' => $result,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function revokeSessions(
        Request $request,
        User $user,
        AuditRecorder $auditRecorder,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        Gate::authorize('revoke-user-account-sessions', $user);

        $user->tokens()->delete();

        $auditRecorder->record(
            $request->user(),
            AuditAction::USER_ACCOUNT_SESSIONS_REVOKED,
            AuditableType::USER_ACCOUNT,
            $user->id,
            null,
            ['sessions_revoked' => true],
            null,
            $contextFactory->fromRequest($request),
        );

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'sessions_revoked' => true,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function destroy(
        Request $request,
        User $user,
        DeleteUnusedUserAccount $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        Gate::authorize('delete-user-account', $user);

        $action->execute($user, $request->user(), $contextFactory->fromRequest($request));

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'deleted' => true,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
