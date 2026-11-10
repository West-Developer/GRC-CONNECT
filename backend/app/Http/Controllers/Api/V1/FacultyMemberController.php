<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Identity\ListFacultyMembers;
use App\Actions\Organization\UpdateFacultyWorkforceProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FacultyMember\UpdateFacultyWorkforceProfileRequest;
use App\Http\Resources\Api\V1\FacultyMemberResource;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FacultyMemberController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(
        Request $request,
        ListFacultyMembers $listFacultyMembers,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('view-faculty-directory');

        $members = $listFacultyMembers->execute(
            $actor,
            $contextFactory->fromRequest($request),
            $request->boolean('include_inactive'),
            $request->string('college')->value() ?: null,
        );

        $response = FacultyMemberResource::collection($members)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @throws AuthenticationException */
    public function updateWorkforceProfile(
        UpdateFacultyWorkforceProfileRequest $request,
        User $facultyMember,
        UpdateFacultyWorkforceProfile $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }
        $this->authorize('update-faculty-workforce-profile', $facultyMember);

        $facultyMember = $action->execute(
            $actor,
            $facultyMember,
            $request->validated(),
            $contextFactory->fromRequest($request),
        );
        $response = FacultyMemberResource::make($facultyMember)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
