<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Identity\InviteFacultyAccount;
use App\Actions\Identity\ListFacultyInvitations;
use App\Actions\Identity\SendFacultyAccountSetupInvitation;
use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FacultyInvitation\StoreFacultyInvitationRequest;
use App\Http\Resources\Api\V1\FacultyInvitationResource;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FacultyInvitationController extends Controller
{
    /** @throws AuthenticationException */
    public function index(Request $request, ListFacultyInvitations $listInvitations): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        abort_unless($actor->college !== null, 403, 'A college must be assigned to view faculty invitations.');

        return $this->cachePrivateResponse(
            FacultyInvitationResource::collection($listInvitations->handle($actor->college))->response($request),
        );
    }

    /** @throws AuthenticationException */
    public function store(
        StoreFacultyInvitationRequest $request,
        InviteFacultyAccount $inviteFaculty,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);

        $faculty = $inviteFaculty->handle(
            $request->validated('email'),
            $actor,
            $contextFactory->fromRequest($request),
        );

        return $this->cachePrivateResponse(
            FacultyInvitationResource::make($faculty)->response($request)->setStatusCode(201),
        );
    }

    /** @throws AuthenticationException */
    public function resend(
        Request $request,
        User $user,
        SendFacultyAccountSetupInvitation $sendInvitation,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        abort_unless(
            $user->role === UserRole::Faculty && $user->college === $actor->college,
            404,
        );

        $sendInvitation->handle($user, $actor, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(
            FacultyInvitationResource::make($user->refresh())->response($request),
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
