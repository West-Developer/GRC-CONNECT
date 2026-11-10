<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\ListEnrollmentChangeRequests;
use App\Actions\Enrollment\RequestEnrollmentChange;
use App\Actions\Enrollment\TransitionEnrollmentChangeRequest;
use App\Domain\Enrollment\EnrollmentChangeRequestType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EnrollmentChangeRequest\IndexEnrollmentChangeRequestRequest;
use App\Http\Requests\Api\V1\EnrollmentChangeRequest\StoreEnrollmentChangeRequestRequest;
use App\Http\Requests\Api\V1\EnrollmentChangeRequest\UpdateEnrollmentChangeRequestRequest;
use App\Http\Resources\Api\V1\EnrollmentChangeRequestResource;
use App\Models\Enrollment;
use App\Models\EnrollmentChangeRequest as EnrollmentChangeRequestModel;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EnrollmentChangeRequestController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(IndexEnrollmentChangeRequestRequest $request, ListEnrollmentChangeRequests $listRequests): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('viewAny', EnrollmentChangeRequestModel::class);

        $requests = $listRequests->execute($actor, $request->validated());

        $response = EnrollmentChangeRequestResource::collection($requests)->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreEnrollmentChangeRequestRequest $request,
        Enrollment $enrollment,
        RequestEnrollmentChange $creator,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('requestChange', $enrollment);

        $type = EnrollmentChangeRequestType::from($request->validated('type'));
        $fromSectionId = $request->validated('from_section_id');
        $toSectionId = $request->validated('to_section_id');

        $changeRequest = $creator->execute(
            $enrollment,
            $type,
            $fromSectionId !== null ? Section::query()->where('id', $fromSectionId)->first() : null,
            $toSectionId !== null ? Section::query()->where('id', $toSectionId)->first() : null,
            $request->validated('reason'),
            $actor,
            $contextFactory->fromRequest($request),
        );

        $response = EnrollmentChangeRequestResource::make($changeRequest)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateEnrollmentChangeRequestRequest $request,
        EnrollmentChangeRequestModel $enrollmentChangeRequest,
        TransitionEnrollmentChangeRequest $transitioner,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('decide', EnrollmentChangeRequestModel::class);

        $changeRequest = $transitioner->execute(
            $enrollmentChangeRequest,
            $request->validated('action'),
            $actor,
            $request->validated('reason'),
            $contextFactory->fromRequest($request),
        );

        $response = EnrollmentChangeRequestResource::make($changeRequest)->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * `private`: an enrollment change request carries an academic record —
     * no shared cache may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
