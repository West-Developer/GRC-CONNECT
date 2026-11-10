<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Academic\ListAcademicGrades;
use App\Actions\Academic\ListGradeApprovalProfessors;
use App\Actions\Academic\ListGradeApprovalSections;
use App\Actions\Academic\LockAllAcademicGrades;
use App\Actions\Academic\RecordAcademicGrade;
use App\Actions\Academic\SubmitSectionGrades;
use App\Actions\Academic\UpdateAcademicGrade;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcademicGrade\ApprovalProfessorsRequest;
use App\Http\Requests\Api\V1\AcademicGrade\ApprovalSectionsRequest;
use App\Http\Requests\Api\V1\AcademicGrade\IndexAcademicGradeRequest;
use App\Http\Requests\Api\V1\AcademicGrade\LockAllAcademicGradesRequest;
use App\Http\Requests\Api\V1\AcademicGrade\StoreAcademicGradeRequest;
use App\Http\Requests\Api\V1\AcademicGrade\UpdateAcademicGradeRequest;
use App\Http\Resources\Api\V1\AcademicGradeResource;
use App\Models\AcademicGrade;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AcademicGradeController extends Controller
{
    /**
     * Which Policy ability governs a PATCH, resolved from the request's
     * `action` field — no `action` means a plain content edit. Same shape
     * as `EnrollmentController::ABILITY_FOR_ACTION` (ADR 0011).
     *
     * @var array<string, string>
     */
    private const ABILITY_FOR_ACTION = [
        'submit' => 'submit',
        'lock' => 'lock',
    ];

    /**
     * @throws AuthenticationException
     */
    public function index(IndexAcademicGradeRequest $request, ListAcademicGrades $listAcademicGrades): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('viewAny', AcademicGrade::class);

        $grades = $listAcademicGrades->execute($actor, $request->validated());

        $response = AcademicGradeResource::collection($grades)->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * The Registrar Head's grade approvals, one row per professor with their real totals.
     *
     * @throws AuthenticationException
     */
    public function approvalProfessors(ApprovalProfessorsRequest $request, ListGradeApprovalProfessors $list): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('lockAll', AcademicGrade::class);

        $result = $list->execute($actor, $request->validated());
        $paginator = $result['paginator'];

        $response = response()->json([
            'data' => collect($paginator->items())->map(static fn (\stdClass $row): array => [
                'professor_id' => $row->professor_id !== null ? (int) $row->professor_id : null,
                'professor_name' => $row->professor_name,
                'college' => $row->college,
                'subject_count' => (int) $row->subject_count,
                'grade_count' => (int) $row->grade_count,
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_grades' => $result['total_grades'],
            ],
        ]);

        return $this->cachePrivateResponse($response);
    }

    /**
     * One professor's sections with grades awaiting lock, with the count in each.
     *
     * @throws AuthenticationException
     */
    public function approvalSections(ApprovalSectionsRequest $request, ListGradeApprovalSections $list): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('lockAll', AcademicGrade::class);

        $response = response()->json([
            'data' => $list->execute($actor, $request->validated())->map(static fn (\stdClass $row): array => [
                'section_id' => $row->section_id !== null ? (int) $row->section_id : null,
                'subject_id' => (int) $row->subject_id,
                'subject_code' => $row->subject_code,
                'subject_title' => $row->subject_title,
                'section_code' => $row->section_code,
                'grade_count' => (int) $row->grade_count,
            ])->values(),
        ]);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreAcademicGradeRequest $request,
        RecordAcademicGrade $recorder,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('create', AcademicGrade::class);

        $grade = $recorder->execute($request->validated(), $actor, $contextFactory->fromRequest($request));

        $response = AcademicGradeResource::make($grade)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateAcademicGradeRequest $request,
        AcademicGrade $academicGrade,
        UpdateAcademicGrade $updater,
        SubmitSectionGrades $submitSectionGrades,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);

        $action = $request->validated('action');
        $ability = is_string($action) && isset(self::ABILITY_FOR_ACTION[$action])
            ? self::ABILITY_FOR_ACTION[$action]
            : 'update';

        $this->authorize($ability, $academicGrade);

        $context = $contextFactory->fromRequest($request);

        if ($action === 'submit') {
            $section = $academicGrade->section;

            if ($section === null) {
                throw ValidationException::withMessages([
                    'action' => 'A section grade cannot be submitted without its section.',
                ]);
            }

            $submitSectionGrades->execute($section, $actor, $context);
            $grade = $academicGrade->refresh()->load(['student', 'subject', 'section']);
        } else {
            $grade = $updater->execute($academicGrade, $request->validated(), $actor, $context);
        }

        return $this->cachePrivateResponse(AcademicGradeResource::make($grade)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function lockAll(
        LockAllAcademicGradesRequest $request,
        LockAllAcademicGrades $locker,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('lockAll', AcademicGrade::class);

        // A term-wide lock touches tens of thousands of grades (plus promotion and
        // reclassification); it is one atomic transaction, so it must not be cut
        // off mid-way by the default PHP request limit.
        set_time_limit(0);

        $result = $locker->execute(
            $request->validated(),
            $actor,
            $contextFactory->fromRequest($request),
        );

        $count = $result['locked_count'];
        $message = $count > 0
            ? "Successfully locked {$count} grade(s)."
            : 'No submitted grades were found awaiting lock.';

        $response = response()->json([
            'data' => [
                'locked_count' => $count,
                'message' => $message,
            ],
        ]);

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
     * `private`: grade records are academic data — no shared cache may
     * retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
