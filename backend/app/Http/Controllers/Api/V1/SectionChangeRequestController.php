<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\DecideSectionChangeRequest;
use App\Actions\Scheduling\ListSectionChangeRequests;
use App\Actions\Scheduling\RequestSectionChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SectionChangeRequest\IndexSectionChangeRequestRequest;
use App\Http\Requests\Api\V1\SectionChangeRequest\StoreSectionChangeRequestRequest;
use App\Http\Requests\Api\V1\SectionChangeRequest\UpdateSectionChangeRequestRequest;
use App\Http\Resources\Api\V1\SectionChangeRequestResource;
use App\Models\Section;
use App\Models\SectionChangeRequest;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Change requests on published sections (stakeholder Doc 14, ADR 0032): the
 * Program Head files one, the Registrar Head approves or rejects it.
 */
final class SectionChangeRequestController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(IndexSectionChangeRequestRequest $request, ListSectionChangeRequests $list): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', SectionChangeRequest::class);

        $requests = $list->execute(
            $user,
            $request->validated('status'),
            $request->validated('section_id') !== null ? (int) $request->validated('section_id') : null,
        );

        return $this->cachePrivateResponse(SectionChangeRequestResource::collection($requests)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreSectionChangeRequestRequest $request,
        Section $section,
        RequestSectionChange $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('requestChange', $section);

        $changeRequest = $action->execute(
            $user,
            $section,
            (array) $request->validated('changes'),
            (string) $request->validated('reason'),
            $contextFactory->fromRequest($request),
        );

        $response = SectionChangeRequestResource::make($changeRequest)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateSectionChangeRequestRequest $request,
        SectionChangeRequest $sectionChangeRequest,
        DecideSectionChangeRequest $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $decision = (string) $request->validated('action');
        $this->authorize(
            $decision === DecideSectionChangeRequest::CANCEL ? 'cancel' : 'decide',
            $sectionChangeRequest,
        );

        $decided = $action->execute(
            $user,
            $sectionChangeRequest,
            $decision,
            $request->validated('decision_reason'),
            $contextFactory->fromRequest($request),
        );

        return $this->cachePrivateResponse(SectionChangeRequestResource::make($decided)->response($request));
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
     * `private`: requests name staff and unpublished schedule changes, so no
     * shared cache may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
