<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\BuildFacultyLoadReport;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\FacultyLoadThreshold;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FacultyLoadReportController extends Controller
{
    public function show(Request $request, AcademicTerm $academicTerm, BuildFacultyLoadReport $report): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->college !== null, 422, 'A college-scoped Program Head or Dean is required.');

        return response()->json(['data' => $report->execute($academicTerm, $user->college->value)]);
    }

    public function updateThreshold(
        Request $request,
        AcademicTerm $academicTerm,
        AuditRecorder $auditRecorder,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->user($request);
        abort_unless($user->college !== null, 422, 'A college-scoped Program Head is required.');
        $data = $request->validate(['max_units' => ['required', 'numeric', 'gt:0', 'max:99']]);
        $existing = FacultyLoadThreshold::query()->where([
            'academic_term_id' => $academicTerm->id,
            'college' => $user->college->value,
        ])->first();
        $threshold = FacultyLoadThreshold::updateOrCreate(
            ['academic_term_id' => $academicTerm->id, 'college' => $user->college->value],
            ['max_units' => $data['max_units'], 'configured_by' => $user->id],
        );
        $auditRecorder->record(
            $user,
            AuditAction::FACULTY_LOAD_THRESHOLD_UPDATED,
            AuditableType::FACULTY_LOAD_THRESHOLD,
            $threshold->id,
            $existing === null ? null : ['max_units' => (float) $existing->max_units],
            ['max_units' => (float) $threshold->max_units],
            null,
            $contextFactory->fromRequest($request),
        );

        return response()->json(['data' => [
            'type' => 'faculty_load_threshold',
            'academic_term_id' => $threshold->academic_term_id,
            'college' => $threshold->college,
            'max_units' => $threshold->max_units,
        ]]);
    }

    /** @throws AuthenticationException */
    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
