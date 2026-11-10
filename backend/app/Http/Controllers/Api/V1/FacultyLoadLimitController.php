<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\ClearFacultyLoadOverride;
use App\Actions\Scheduling\SaveFacultyLoadLimit;
use App\Actions\Scheduling\SaveFacultyLoadOverride;
use App\Domain\Identity\FacultyEmploymentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FacultyLoad\UpdateFacultyLoadLimitRequest;
use App\Http\Requests\Api\V1\FacultyLoad\UpdateFacultyLoadOverrideRequest;
use App\Models\AcademicTerm;
use App\Models\FacultyLoadLimit;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Teaching-load limits per employment type and per-professor overrides
 * (stakeholder Doc 14, ADR 0033). Program Head and Dean, own college only.
 */
final class FacultyLoadLimitController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function updateLimit(
        UpdateFacultyLoadLimitRequest $request,
        AcademicTerm $academicTerm,
        FacultyEmploymentType $employmentType,
        SaveFacultyLoadLimit $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('manage', FacultyLoadLimit::class);

        $limit = $action->execute(
            $actor,
            $academicTerm,
            $employmentType,
            (float) $request->validated('max_units'),
            $contextFactory->fromRequest($request),
        );

        return response()->json(['data' => [
            'type' => 'faculty_load_limit',
            'academic_term_id' => $limit->academic_term_id,
            'college' => $limit->college,
            'employment_type' => $limit->employment_type->value,
            'max_units' => $limit->max_units,
        ]]);
    }

    /**
     * @throws AuthenticationException
     */
    public function updateOverride(
        UpdateFacultyLoadOverrideRequest $request,
        AcademicTerm $academicTerm,
        User $professor,
        SaveFacultyLoadOverride $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('manage', FacultyLoadLimit::class);

        $override = $action->execute(
            $actor,
            $academicTerm,
            $professor,
            (float) $request->validated('max_units'),
            (string) $request->validated('reason'),
            $contextFactory->fromRequest($request),
        );

        return response()->json(['data' => [
            'type' => 'faculty_load_override',
            'academic_term_id' => $override->academic_term_id,
            'professor_id' => $override->professor_id,
            'max_units' => $override->max_units,
            'reason' => $override->reason,
        ]]);
    }

    /**
     * @throws AuthenticationException
     */
    public function destroyOverride(
        Request $request,
        AcademicTerm $academicTerm,
        User $professor,
        ClearFacultyLoadOverride $action,
        AuditRequestContextFactory $contextFactory,
    ): Response {
        $actor = $this->authenticatedUser($request);
        $this->authorize('manage', FacultyLoadLimit::class);

        $action->execute($actor, $academicTerm, $professor, $contextFactory->fromRequest($request));

        return response()->noContent();
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
