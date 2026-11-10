<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organization\AutoAssignSectionScheduleReferences;
use App\Actions\Organization\SaveSectionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SectionPlan\IndexSectionPlanRequest;
use App\Http\Requests\Api\V1\SectionPlan\StoreSectionPlanRequest;
use App\Http\Resources\Api\V1\AcademicTermSectionPlanResource;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicTermSectionPlanController extends Controller
{
    public function index(IndexSectionPlanRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', AcademicTermSectionPlan::class);

        $plans = AcademicTermSectionPlan::query()
            ->where('academic_term_id', $request->validated('academic_term_id'))
            ->when($request->validated('curriculum_id'), fn ($query, $curriculumId) => $query->where('curriculum_id', $curriculumId))
            ->when($user->role->value === 'program_chair', fn ($query) => $query->where('college', $user->college?->value))
            ->orderBy('year_level')
            ->get();

        return AcademicTermSectionPlanResource::collection($plans)->response($request);
    }

    public function store(
        StoreSectionPlanRequest $request,
        AcademicTerm $academicTerm,
        SaveSectionPlan $action,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', AcademicTermSectionPlan::class);

        $plans = $action->save(
            $academicTerm,
            (int) $request->validated('curriculum_id'),
            $user,
            $request->validated('counts'),
            $request->validated('students_per_block') ?? [],
        );

        return AcademicTermSectionPlanResource::collection(collect($plans))->response($request);
    }

    public function release(
        Request $request,
        AcademicTerm $academicTerm,
        SaveSectionPlan $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', AcademicTermSectionPlan::class);
        $input = $request->validate([
            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],
            'year_level' => ['sometimes', 'integer', 'between:1,4'],
        ]);
        $curriculumId = (int) $input['curriculum_id'];

        $plans = $action->release($academicTerm, $curriculumId, $user, $contextFactory->fromRequest($request), isset($input['year_level']) ? (int) $input['year_level'] : null);

        return AcademicTermSectionPlanResource::collection(collect($plans))->response($request);
    }

    public function autoAssign(
        Request $request,
        AcademicTerm $academicTerm,
        AutoAssignSectionScheduleReferences $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', AcademicTermSectionPlan::class);
        $input = $request->validate([
            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],
            'year_level' => ['sometimes', 'integer', 'between:1,4'],
        ]);
        $curriculumId = (int) $input['curriculum_id'];

        // The action re-derives and enforces the college itself; this local
        // copy only scopes the response payload, exactly as release/submit do.
        $college = $user->college?->value;

        $sections = $action->execute(
            $academicTerm,
            $curriculumId,
            $user,
            $contextFactory->fromRequest($request),
            isset($input['year_level']) ? (int) $input['year_level'] : null,
        );

        $plans = AcademicTermSectionPlan::query()
            ->where('academic_term_id', $academicTerm->id)
            ->where('curriculum_id', $curriculumId)
            ->where('college', $college)
            ->get();

        return AcademicTermSectionPlanResource::collection($plans)->additional([
            'meta' => ['sections_updated' => $sections->count()],
        ])->response($request);
    }

    public function submit(
        Request $request,
        AcademicTerm $academicTerm,
        SaveSectionPlan $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', AcademicTermSectionPlan::class);
        $curriculumId = (int) $request->validate(['curriculum_id' => ['required', 'integer', 'exists:curricula,id']])['curriculum_id'];
        $proposal = $action->submit($academicTerm, $curriculumId, $user, $contextFactory->fromRequest($request));

        return response()->json(['data' => [
            'type' => 'schedule_proposal',
            'id' => $proposal->id,
            'academic_term_id' => $proposal->academic_term_id,
            'college' => $proposal->college,
            'status' => $proposal->status->value,
            'status_label' => $proposal->status->label(),
        ]]);
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
}
