<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Dashboard\BuildEnrollmentSummary;
use App\Domain\Organization\AcademicTermStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\IndexDashboardRequest;
use App\Http\Resources\Api\V1\Dashboard\EnrollmentSummaryResource;
use App\Models\AcademicTerm;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class EnrollmentSummaryController extends Controller
{
    public function __invoke(
        IndexDashboardRequest $request,
        BuildEnrollmentSummary $buildEnrollmentSummary,
    ): JsonResponse {
        $this->authorize('view-enrollment-summary');

        $termId = $request->validated('academic_term_id');
        $term = $termId !== null
            ? AcademicTerm::query()->where('id', $termId)->firstOrFail()
            : AcademicTerm::query()->where('status', AcademicTermStatus::SemesterOngoing)->first();

        if (! $term instanceof AcademicTerm) {
            throw new NotFoundHttpException('No academic_term_id was given and no term is currently active.');
        }

        $summary = $buildEnrollmentSummary->execute($term);

        $response = EnrollmentSummaryResource::make($summary)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
