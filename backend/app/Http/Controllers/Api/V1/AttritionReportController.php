<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Analytics\BuildAttritionReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Analytics\IndexAttritionRequest;
use App\Http\Resources\Api\V1\AttritionReportResource;
use App\Models\AcademicTerm;
use Illuminate\Http\JsonResponse;

final class AttritionReportController extends Controller
{
    public function __invoke(IndexAttritionRequest $request, BuildAttritionReport $report): JsonResponse
    {
        $this->authorize('view-attrition-report');
        $validated = $request->validated();
        $baseline = AcademicTerm::query()->findOrFail($validated['baseline_academic_term_id']);
        $comparison = AcademicTerm::query()->findOrFail($validated['comparison_academic_term_id']);

        $response = AttritionReportResource::make($report->execute($baseline, $comparison, $validated))->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
