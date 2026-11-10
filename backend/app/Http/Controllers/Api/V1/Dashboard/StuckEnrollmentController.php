<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Dashboard\ListStuckEnrollments;
use App\Domain\Organization\AcademicTermStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\IndexDashboardRequest;
use App\Http\Resources\Api\V1\Dashboard\StuckEnrollmentResource;
use App\Models\AcademicTerm;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @deprecated No screen calls this since stakeholder Doc 14 (S16 retired the
 *             Stuck Students module and the dashboard's "past threshold" split;
 *             ADR 0024 amendment). Kept until the owner confirms removing it
 *             together with `StuckEnrollmentPolicy` and the dwell-threshold entry
 *             in `BuildPolicySettingsSummary`.
 */
final class StuckEnrollmentController extends Controller
{
    public function __invoke(
        IndexDashboardRequest $request,
        ListStuckEnrollments $listStuckEnrollments,
    ): JsonResponse {
        $this->authorize('view-stuck-enrollments');

        $termId = $request->validated('academic_term_id');
        $term = $termId !== null
            ? AcademicTerm::query()->where('id', $termId)->firstOrFail()
            : AcademicTerm::query()->where('status', AcademicTermStatus::SemesterOngoing)->first();

        if (! $term instanceof AcademicTerm) {
            throw new NotFoundHttpException('No academic_term_id was given and no term is currently active.');
        }

        $rows = $listStuckEnrollments->execute($term);
        $thresholdDays = config('enrollment.dashboard.stuck_threshold_days');

        $response = StuckEnrollmentResource::collection($rows)
            ->additional([
                'meta' => [
                    'threshold_configured' => $thresholdDays !== null,
                    'threshold_days' => $thresholdDays !== null ? (int) $thresholdDays : null,
                    'academic_term_id' => $term->id,
                ],
            ])
            ->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
