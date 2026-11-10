<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AcademicTermSectionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AcademicTermSectionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AcademicTermSectionPlan $plan */
        $plan = $this->resource;

        return [
            'type' => 'academic-term-section-plan',
            'id' => $plan->id,
            'academic_term_id' => $plan->academic_term_id,
            'curriculum_id' => $plan->curriculum_id,
            'college' => $plan->college,
            'year_level' => $plan->year_level,
            'section_count' => $plan->section_count,
            'students_per_block' => $plan->students_per_block,
            'status' => $plan->status->value,
            'status_label' => ucfirst($plan->status->value),
            'submitted_at' => $plan->submitted_at?->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'recommendation_source' => $plan->recommendation_source,
            'recommended_section_count' => $plan->recommended_section_count,
            'recommendation_is_overridden' => $plan->recommendation_is_overridden,
            'recommendation_prediction_run_id' => $plan->recommendation_prediction_run_id,
        ];
    }
}
