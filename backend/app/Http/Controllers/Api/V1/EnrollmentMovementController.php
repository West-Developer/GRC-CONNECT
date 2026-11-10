<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Analytics\BuildEnrollmentMovementReport;
use App\Actions\Registrar\RecordProgramShift;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Analytics\IndexEnrollmentMovementRequest;
use App\Http\Requests\Api\V1\Analytics\StoreProgramShiftRequest;
use App\Models\AcademicTerm;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Drops, withdrawals, and course shifts for Enrollment Analytics, and the
 * Registrar's record of a course shift (stakeholder Doc 14, ADR 0034).
 */
final class EnrollmentMovementController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(IndexEnrollmentMovementRequest $request, BuildEnrollmentMovementReport $report): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('view-enrollment-movements');

        $data = $report->execute(
            $actor,
            AcademicTerm::query()->findOrFail((int) $request->validated('academic_term_id')),
            (string) $request->validated('type'),
            $request->validated('college'),
        );

        return $this->noStore(response()->json(['data' => ['type' => 'enrollment_movements', ...$data]]));
    }

    /**
     * 201 when the shift is recorded, 200 when the same shift was already there.
     *
     * @throws AuthenticationException
     */
    public function storeShift(
        StoreProgramShiftRequest $request,
        RecordProgramShift $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('record-program-shift');

        $result = $action->execute(
            $actor,
            StudentProfile::query()->where('student_number', (string) $request->validated('student_number'))->firstOrFail(),
            (int) $request->validated('to_program_id'),
            AcademicTerm::query()->findOrFail((int) $request->validated('academic_term_id')),
            (string) $request->validated('reason'),
            $contextFactory->fromRequest($request),
        );

        $shift = $result['shift'];
        $response = response()->json(['data' => [
            'type' => 'program_shift',
            'id' => $shift->id,
            'from_program_id' => $shift->from_program_id,
            'to_program_id' => $shift->to_program_id,
            'academic_term_id' => $shift->academic_term_id,
        ]], $result['created'] ? 201 : 200);

        return $this->noStore($response);
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

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
