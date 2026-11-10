<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\CreateSection;
use App\Actions\Scheduling\UpdateSection;
use App\Domain\Scheduling\PublishedSectionLock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Section\StoreSectionRequest;
use App\Http\Requests\Api\V1\Section\UpdateSectionRequest;
use App\Http\Resources\Api\V1\SectionResource;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SectionController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $this->authorize('viewAny', Section::class);

        $sections = Section::query()
            ->visibleTo($user)
            ->with('professor:id,name')
            ->orderBy('academic_term_id')
            ->orderBy('subject_id')
            ->orderBy('section_code')
            ->get();

        return $this->cachePrivateResponse(SectionResource::collection($sections)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreSectionRequest $request,
        CreateSection $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('create', Section::class);

        $section = $action->execute($user, [
            'academic_term_id' => $request->validated('academic_term_id'),
            'subject_id' => $request->validated('subject_id'),
            'section_code' => $request->validated('section_code'),
            'professor_id' => $request->validated('professor_id'),
            'schedule_days' => $request->validated('schedule_days'),
            'starts_at_time' => $request->validated('starts_at_time'),
            'ends_at_time' => $request->validated('ends_at_time'),
            'room' => $request->validated('room'),
            'modality' => $request->validated('modality'),
            'capacity' => $request->validated('capacity'),
            'viability_threshold' => $request->validated('viability_threshold'),
            'status' => $request->validated('status'),
        ], $contextFactory->fromRequest($request));

        $response = SectionResource::make($section)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateSectionRequest $request,
        Section $section,
        UpdateSection $action,
        PublishedSectionLock $lock,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $section);
        $lock->assertAllowed($user, $section, $request->validated());

        $section = $action->execute($user, [
            'academic_term_id' => $request->validated('academic_term_id'),
            'subject_id' => $request->validated('subject_id'),
            'section_code' => $request->validated('section_code'),
            'professor_id' => $request->validated('professor_id'),
            'schedule_days' => $request->validated('schedule_days'),
            'starts_at_time' => $request->validated('starts_at_time'),
            'ends_at_time' => $request->validated('ends_at_time'),
            'room' => $request->validated('room'),
            'modality' => $request->validated('modality'),
            'capacity' => $request->validated('capacity'),
            'viability_threshold' => $request->validated('viability_threshold'),
            'status' => $request->validated('status'),
            'override_reason' => $request->validated('override_reason'),
        ], $section, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(SectionResource::make($section)->response($request));
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
     * `private`: results and authoring rights differ by role, so no shared
     * cache may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
