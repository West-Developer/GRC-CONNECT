<?php

namespace Tests\Unit\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\AcademicTermSectionPlanResource;
use App\Models\AcademicTermSectionPlan;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The Demand Forecast dialog's "why this section was generated" view needs
 * to tell a forecast-driven block apart from one the Program Chair typed by
 * hand, and to know when a forecast-driven block was later overridden — all
 * of which already exists on the model but was not yet serialized here.
 */
final class AcademicTermSectionPlanResourceTest extends TestCase
{
    public function test_resource_exposes_recommendation_metadata_for_a_predictive_plan(): void
    {
        $plan = new AcademicTermSectionPlan;
        $plan->forceFill([
            'id' => 5,
            'academic_term_id' => 3,
            'curriculum_id' => 10,
            'college' => 'ccs',
            'year_level' => 1,
            'section_count' => 2,
            'students_per_block' => 40,
            'status' => 'draft',
            'submitted_at' => null,
            'recommendation_source' => 'predictive',
            'recommended_section_count' => 2,
            'recommendation_is_overridden' => false,
            'recommendation_prediction_run_id' => 44,
        ]);

        $data = (new AcademicTermSectionPlanResource($plan))->toArray(Request::create('/'));

        self::assertSame('predictive', $data['recommendation_source']);
        self::assertSame(2, $data['recommended_section_count']);
        self::assertFalse($data['recommendation_is_overridden']);
        self::assertSame(44, $data['recommendation_prediction_run_id']);
    }

    public function test_resource_exposes_null_recommendation_metadata_for_a_manually_planned_block(): void
    {
        $plan = new AcademicTermSectionPlan;
        $plan->forceFill([
            'id' => 6,
            'academic_term_id' => 3,
            'curriculum_id' => 10,
            'college' => 'ccs',
            'year_level' => 2,
            'section_count' => 1,
            'students_per_block' => 40,
            'status' => 'draft',
            'submitted_at' => null,
            'recommendation_source' => null,
            'recommended_section_count' => null,
            'recommendation_is_overridden' => true,
            'recommendation_prediction_run_id' => null,
        ]);

        $data = (new AcademicTermSectionPlanResource($plan))->toArray(Request::create('/'));

        self::assertNull($data['recommendation_source']);
        self::assertNull($data['recommended_section_count']);
        self::assertTrue($data['recommendation_is_overridden']);
        self::assertNull($data['recommendation_prediction_run_id']);
    }
}
