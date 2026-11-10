<?php

namespace App\Actions\Scheduling;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\CapacitySource;
use App\Domain\Organization\SectionBlockCode;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\ScheduleGenerationWarningType;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTermSectionPlan;
use App\Models\CurriculumSubject;
use App\Models\PredictionRun;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDemandForecast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Converts advisory demand forecasts into draft-only section recommendations.
 *
 * Existing manual plans, manually edited section values, and submitted plans
 * are deliberately left untouched. A Chair can see a newer recommendation,
 * then explicitly decide whether to apply it in the normal draft workflow.
 */
final class ApplyDemandForecastToDraft
{
    /**
     * @return list<array{type: string, message: string, entity_id: ?int}> warnings that should be shown with the generation run
     */
    public function execute(ScheduleGenerationRun $generationRun, PredictionRun $predictionRun): array
    {
        $generationRun->loadMissing('academicTerm');
        $term = $generationRun->academicTerm;
        $isDraftWorkflow = $term->collegeWorkflows()
            ->where('college', $generationRun->college)
            ->where('stage', AcademicTermCollegeWorkflowStage::SchedulePreparation)
            ->exists();

        return DB::transaction(function () use ($generationRun, $predictionRun, $term, $isDraftWorkflow): array {
            $forecastRows = SectionDemandForecast::query()
                ->where('prediction_run_id', $predictionRun->id)
                ->where('academic_term_id', $term->id)
                ->get();

            if ($forecastRows->isEmpty()) {
                return [];
            }

            $forecasts = $forecastRows
                ->filter(fn (SectionDemandForecast $forecast): bool => $forecast->curriculum_id !== null && $forecast->year_level !== null)
                ->keyBy(fn (SectionDemandForecast $forecast): string => $forecast->curriculum_id.':'.$forecast->subject_id.':'.$forecast->year_level);
            $legacyForecasts = $forecastRows
                ->filter(fn (SectionDemandForecast $forecast): bool => $forecast->curriculum_id === null || $forecast->year_level === null)
                ->keyBy('subject_id');

            $placements = CurriculumSubject::query()
                ->with('curriculum.program')
                ->where(function ($query) use ($term): void {
                    $query->where('semester', $term->semester)
                        ->orWhere('semester', 'like', '%'.$term->semester.'%');
                })
                ->whereHas('curriculum.program', fn ($programs) => $programs
                    ->where('college', $generationRun->college)
                    // The Teacher Certificate Program is a one-year intake,
                    // not a 4-year degree — out of the automation's
                    // documented 1st-4th year scope.
                    ->where('code', '!=', 'TCP'))
                ->get()
                ->filter(function (CurriculumSubject $placement) use ($forecasts, $legacyForecasts): bool {
                    $cohortKey = $placement->curriculum_id.':'.$placement->subject_id.':'.$placement->year_level;

                    return $forecasts->has($cohortKey)
                        || ($placement->curriculum->status === CurriculumStatus::Active && $legacyForecasts->has($placement->subject_id));
                })
                ->groupBy(fn (CurriculumSubject $placement): string => $placement->curriculum_id.':'.$placement->year_level);

            $warnings = [];
            foreach ($placements as $group) {
                /** @var CurriculumSubject $firstPlacement */
                $firstPlacement = $group->first();
                $recommendedSectionCount = (int) $group
                    ->map(function (CurriculumSubject $placement) use ($forecasts, $legacyForecasts): int {
                        $cohortKey = $placement->curriculum_id.':'.$placement->subject_id.':'.$placement->year_level;
                        /** @var SectionDemandForecast $forecast */
                        $forecast = $forecasts->get($cohortKey) ?? $legacyForecasts->get($placement->subject_id);

                        return (int) $forecast->suggested_section_count;
                    })
                    ->max();

                $plan = AcademicTermSectionPlan::query()
                    ->where('academic_term_id', $term->id)
                    ->where('curriculum_id', $firstPlacement->curriculum_id)
                    ->where('college', $generationRun->college)
                    ->where('year_level', $firstPlacement->year_level)
                    ->lockForUpdate()
                    ->first();

                if ($plan !== null && $plan->status === SectionPlanStatus::Submitted) {
                    if ($isDraftWorkflow) {
                        $plan->update([
                            'status' => SectionPlanStatus::Draft,
                            'submitted_by' => null,
                            'submitted_at' => null,
                        ]);
                    } else {
                        $warnings[] = [
                            'type' => ScheduleGenerationWarningType::SectionPlanSubmittedSkip->value,
                            'message' => "Skipped submitted {$firstPlacement->year_level}th-year section plan.",
                            'entity_id' => $firstPlacement->curriculum_id,
                        ];
                        continue;
                    }
                }

                if ($plan === null) {
                    $plan = AcademicTermSectionPlan::create([
                        'academic_term_id' => $term->id,
                        'curriculum_id' => $firstPlacement->curriculum_id,
                        'college' => $generationRun->college,
                        'year_level' => $firstPlacement->year_level,
                        'section_count' => $recommendedSectionCount,
                        'students_per_block' => 40,
                        'recommendation_source' => 'predictive',
                        'recommended_section_count' => $recommendedSectionCount,
                        'recommendation_is_overridden' => false,
                        'recommendation_prediction_run_id' => $predictionRun->id,
                        'status' => SectionPlanStatus::Draft,
                    ]);
                } elseif ($plan->recommendation_source === 'predictive' && ! $plan->recommendation_is_overridden) {
                    $plan->update([
                        'section_count' => $recommendedSectionCount,
                        'recommendation_source' => 'predictive',
                        'recommended_section_count' => $recommendedSectionCount,
                        'recommendation_prediction_run_id' => $predictionRun->id,
                    ]);
                } else {
                    $hasExistingSections = Section::query()->where('section_plan_id', $plan->id)->exists();

                    if (! $hasExistingSections) {
                        $plan->update([
                            'section_count' => $recommendedSectionCount,
                            'recommendation_source' => 'predictive',
                            'recommended_section_count' => $recommendedSectionCount,
                            'recommendation_is_overridden' => false,
                            'recommendation_prediction_run_id' => $predictionRun->id,
                        ]);
                    } else {
                        $plan->update([
                            'recommendation_source' => 'predictive',
                            'recommended_section_count' => $recommendedSectionCount,
                            'recommendation_is_overridden' => true,
                            'recommendation_prediction_run_id' => $predictionRun->id,
                        ]);
                        $warnings[] = [
                            'type' => ScheduleGenerationWarningType::ManualSectionCountKept->value,
                            'message' => "Kept the existing manual {$firstPlacement->year_level}th-year section count.",
                            'entity_id' => $firstPlacement->curriculum_id,
                        ];

                        continue;
                    }
                }

                $this->materializePredictiveBlocks(
                    $term->id,
                    $plan,
                    $predictionRun,
                    $firstPlacement,
                    $group,
                );
            }

            return array_values(array_unique($warnings, SORT_REGULAR));
        });
    }

    /**
     * @param  Collection<int, CurriculumSubject>  $placements
     */
    private function materializePredictiveBlocks(
        int $academicTermId,
        AcademicTermSectionPlan $plan,
        PredictionRun $predictionRun,
        CurriculumSubject $firstPlacement,
        Collection $placements,
    ): void {
        $program = $firstPlacement->curriculum->program;

        for ($number = 1; $number <= $plan->section_count; $number++) {
            $code = SectionBlockCode::fromProgram(
                $program->code,
                $program->college,
                $plan->year_level,
                $number,
            );

            foreach ($placements as $placement) {
                Section::query()->firstOrCreate(
                    [
                        'academic_term_id' => $academicTermId,
                        'subject_id' => $placement->subject_id,
                        'section_code' => $code,
                    ],
                    [
                        'section_plan_id' => $plan->id,
                        'capacity' => $plan->students_per_block,
                        'capacity_source' => CapacitySource::Plan,
                        'recommendation_source' => 'predictive',
                        'recommended_capacity' => $plan->students_per_block,
                        'recommendation_prediction_run_id' => $predictionRun->id,
                        'is_block_exclusive' => true,
                        'status' => SectionStatus::Planned,
                    ],
                );
            }
        }
    }
}
