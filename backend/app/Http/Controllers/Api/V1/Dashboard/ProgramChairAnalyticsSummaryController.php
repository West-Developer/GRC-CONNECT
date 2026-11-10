<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Analytics\BuildProgramChairAnalyticsSummary;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\IndexDashboardRequest;
use App\Http\Resources\Api\V1\Dashboard\ProgramChairAnalyticsSummaryResource;
use App\Models\AcademicTerm;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProgramChairAnalyticsSummaryController extends Controller
{
    public function __invoke(
        IndexDashboardRequest $request,
        BuildProgramChairAnalyticsSummary $buildProgramChairAnalyticsSummary,
    ): JsonResponse {
        $this->authorize('view-analytics');

        $actor = $request->user();
        $requestedDepartment = $request->validated('department');
        $college = match ($actor->role) {
            UserRole::ProgramChair => $actor->college,
            UserRole::RegistrarHead => $requestedDepartment === null
                ? null
                : CollegeCode::from($requestedDepartment),
            default => null,
        };

        if ($actor->role === UserRole::ProgramChair && $college === null) {
            abort(403, 'No college is assigned to this Program Head account.');
        }

        if (
            $actor->role === UserRole::ProgramChair
            && $requestedDepartment !== null
            && $requestedDepartment !== $college->value
        ) {
            abort(403, 'Program Head analytics are limited to the assigned college.');
        }

        $termId = $request->validated('academic_term_id');
        $term = $termId !== null
            ? AcademicTerm::query()->where('id', $termId)->firstOrFail()
            : AcademicTerm::query()->where('status', AcademicTermStatus::SemesterOngoing)->first();

        if (! $term instanceof AcademicTerm) {
            throw new NotFoundHttpException('No academic_term_id was given and no term is currently active.');
        }

        $summary = $buildProgramChairAnalyticsSummary->execute(
            $term,
            $college,
            $request->validated('year_level'),
            $request->validated('trend_school_year'),
            $request->validated('trend_semester'),
            $request->validated('trend_school_year_from'),
            $request->validated('trend_school_year_to'),
        );

        $response = ProgramChairAnalyticsSummaryResource::make($summary)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
