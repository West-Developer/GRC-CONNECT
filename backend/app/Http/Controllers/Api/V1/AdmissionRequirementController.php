<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Identity\BuildAdmissionChecklist;
use App\Actions\Identity\CreateAdmissionRequirementType;
use App\Actions\Identity\ListApplicableAdmissionRequirements;
use App\Actions\Identity\SetAdmissionRequirementSubmitted;
use App\Domain\Identity\AdmissionIntakeDefaults;
use App\Domain\Identity\AdmissionRequirementCategory;
use App\Domain\Identity\StudentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StudentProfile\IndexAdmissionRequirementTypesRequest;
use App\Http\Requests\Api\V1\StudentProfile\SetAdmissionRequirementRequest;
use App\Http\Requests\Api\V1\StudentProfile\StoreAdmissionRequirementTypeRequest;
use App\Models\AdmissionRequirementType;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Admission requirements checklist (stakeholder Doc 14, ADR 0037): a
 * Student reads their own; Admission Staff read and tick any student's and add
 * requirements to the shared list.
 */
final class AdmissionRequirementController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function showOwn(Request $request, BuildAdmissionChecklist $build): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $student = StudentProfile::query()->where('user_id', $actor->id)->first();
        abort_unless($student instanceof StudentProfile, 403, 'Only a Student has an Admission checklist of their own.');
        $this->authorize('viewAdmissionRequirements', $student);

        return $this->json($build->execute($student->load('user')));
    }

    /**
     * @throws AuthenticationException
     */
    public function show(Request $request, StudentProfile $studentProfile, BuildAdmissionChecklist $build): JsonResponse
    {
        $this->authenticatedUser($request);
        $this->authorize('viewAdmissionRequirements', $studentProfile);

        return $this->json($build->execute($studentProfile->load('user')));
    }

    /**
     * Sets one requirement's submitted state and returns the whole checklist.
     * A repeat of the current state changes nothing.
     *
     * @throws AuthenticationException
     */
    public function update(
        SetAdmissionRequirementRequest $request,
        StudentProfile $studentProfile,
        AdmissionRequirementType $requirementType,
        SetAdmissionRequirementSubmitted $set,
        BuildAdmissionChecklist $build,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('manageAdmissionRequirements', $studentProfile);

        $set->execute(
            $studentProfile,
            $requirementType,
            $request->boolean('is_submitted'),
            $actor,
            $contextFactory->fromRequest($request),
        );

        return $this->json($build->execute($studentProfile->load('user')));
    }

    /**
     * The requirements that apply to a new student of a type, for the Create Account checklist.
     *
     * @throws AuthenticationException
     */
    public function indexTypes(
        IndexAdmissionRequirementTypesRequest $request,
        ListApplicableAdmissionRequirements $list,
    ): JsonResponse {
        $this->authenticatedUser($request);
        $this->authorize('viewAny', AdmissionRequirementType::class);

        $studentType = $request->validated('student_type') !== null
            ? StudentType::from((string) $request->validated('student_type'))
            : AdmissionIntakeDefaults::studentTypeFor((int) $request->validated('year_level'));

        $response = response()->json([
            'data' => [
                'type' => 'admission_requirement_selection',
                ...$list->execute($studentType),
            ],
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @throws AuthenticationException
     */
    public function storeType(
        StoreAdmissionRequirementTypeRequest $request,
        CreateAdmissionRequirementType $create,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('create', AdmissionRequirementType::class);

        $type = $create->execute(
            (string) $request->validated('name'),
            AdmissionRequirementCategory::from((string) $request->validated('category')),
            $actor,
            $contextFactory->fromRequest($request),
        );

        $response = response()->json([
            'data' => [
                'type' => 'admission_requirement_type',
                'id' => $type->id,
                'category' => $type->category->value,
                'name' => $type->name,
                'is_system' => $type->is_system,
            ],
        ], 201);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @param  array<string, mixed>  $checklist
     */
    private function json(array $checklist): JsonResponse
    {
        $response = response()->json(['data' => ['type' => 'admission_requirements', ...$checklist]]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
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
}
