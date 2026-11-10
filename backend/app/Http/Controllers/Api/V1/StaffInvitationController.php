<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Identity\InviteStaffAccount;
use App\Actions\Identity\ListStaffInvitations;
use App\Actions\Identity\SendStaffAccountSetupInvitation;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StaffInvitation\StoreStaffInvitationRequest;
use App\Http\Resources\Api\V1\StaffInvitationResource;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffInvitationController extends Controller
{
    /** @throws AuthenticationException */
    public function index(Request $request, ListStaffInvitations $listInvitations): JsonResponse
    {
        $this->authenticatedUser($request);

        return $this->cachePrivateResponse(
            StaffInvitationResource::collection($listInvitations->handle())->response($request),
        );
    }

    /** @throws AuthenticationException */
    public function store(
        StoreStaffInvitationRequest $request,
        InviteStaffAccount $inviteStaff,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $college = $request->validated('college') ? CollegeCode::tryFrom($request->validated('college')) : null;

        $staff = $inviteStaff->handle(
            $request->validated('email'),
            UserRole::from((string) $request->validated('role')),
            $actor,
            $contextFactory->fromRequest($request),
            $college,
            $request->validated('masters_degree'),
        );

        return $this->cachePrivateResponse(
            StaffInvitationResource::make($staff)->response($request)->setStatusCode(201),
        );
    }

    /** @throws AuthenticationException */
    public function resend(
        Request $request,
        User $user,
        SendStaffAccountSetupInvitation $sendInvitation,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        abort_unless(in_array($user->role, UserRole::registrarInvitableCases(), true), 404);

        $sendInvitation->handle($user, $actor, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(
            StaffInvitationResource::make($user->refresh())->response($request),
        );
    }

    /** @throws AuthenticationException */
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
