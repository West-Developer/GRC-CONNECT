<?php

namespace App\Actions\Analytics;

use App\Actions\Scheduling\ApplyDemandForecastToDraft;
use App\Actions\Scheduling\GenerateFacultyAssignmentRecommendations;
use App\Domain\Analytics\HistoricalCohortReference;
use App\Domain\Analytics\HistoricalCohortResolver;
use App\Domain\Analytics\PredictionRunStatus;
use App\Domain\Analytics\PredictionType;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Scheduling\ScheduleGenerationStatus;
use App\Domain\Scheduling\ScheduleGenerationWarningType;
use App\Models\CurriculumSubject;
use App\Models\PredictionRun;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionDemandForecast;
use App\Models\SectionDemandObservation;
use App\Models\StudentProfile;
use App\Services\Analytics\SectionDemandPredictionClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Produces advisory, aggregate-only section demand forecasts. Forecasts are
 * calculated at program/curriculum/year cohort grain and copied to that
 * cohort's current-term subjects, so one recommendation controls all blocks.
 */
final class GenerateSectionDemandForecasts
{
    public function __construct(
        private readonly HistoricalCohortResolver $historicalCohortResolver,
        private readonly SectionDemandPredictionClient $predictionClient,
        private readonly ApplyDemandForecastToDraft $applyDemandForecastToDraft,
        private readonly GenerateFacultyAssignmentRecommendations $facultyRecommendations,
    ) {}

    public function execute(ScheduleGenerationRun $generationRun): void
    {
        $generationRun->loadMissing('academicTerm');
        $term = $generationRun->academicTerm;

        $generationRun->update([
            'status' => ScheduleGenerationStatus::Running,
            'started_at' => now(),
            'error_summary' => null,
        ]);

        $predictionRun = PredictionRun::create([
            'type' => PredictionType::SectionDemand,
            'academic_term_id' => $term->id,
            'model_version' => 'section-demand-rf-v2',
            'feature_schema_version' => 'v2',
            'status' => PredictionRunStatus::Running,
            'started_at' => now(),
        ]);
        $generationRun->update(['prediction_run_id' => $predictionRun->id]);

        try {
            $placements = $this->currentTermPlacements($generationRun);
            $cohorts = $this->eligibleCohorts($placements);

            if ($cohorts->isEmpty()) {
                $this->completeWithoutPlacements($generationRun, $predictionRun);

                return;
            }

            /** @var array<string, array{forecast: array<string, mixed>, history: HistoricalCohortReference, observation_count: int, strategy: string}> $forecastByCohort */
            $forecastByCohort = [];
            $warnings = [];
            $totalObservationCount = 0;
            $serviceFallbackCount = 0;

            foreach ($cohorts as $cohort) {
                $history = $this->historicalCohortResolver->resolve(
                    $term->school_year,
                    $term->semester,
                    $cohort['year_level'],
                );
                $observations = $this->realHistoricalObservations($cohort, $history);

                if ($observations === []) {
                    continue;
                }

                $target = [[
                    'key' => $cohort['key'],
                    'cohort_size' => $cohort['cohort_size'],
                    'section_count' => $observations[array_key_last($observations)]['section_count'],
                    'recommended_capacity' => 40,
                    'year_level' => $cohort['year_level'],
                    'semester' => $term->semester,
                ]];

                try {
                    $response = $this->predictionClient->predict($observations, $target);
                    $strategy = (string) ($response['strategy'] ?? 'unknown');
                } catch (Throwable $exception) {
                    report($exception);
                    $response = $this->localBaselineResponse($observations, $target);
                    $strategy = 'historical_baseline';
                    $serviceFallbackCount++;
                    $warnings[] = [
                        'type' => ScheduleGenerationWarningType::PredictionServiceUnavailable->value,
                        'message' => 'Prediction service was unavailable; generated an editable draft from validated historical cohort data.',
                        'entity_id' => $cohort['curriculum_id'],
                    ];
                }

                $forecast = collect($response['forecasts'] ?? [])->firstWhere('key', $cohort['key']);
                if (! is_array($forecast)) {
                    $warnings[] = [
                        'type' => ScheduleGenerationWarningType::NoForecastReturned->value,
                        'message' => "No demand forecast returned for {$cohort['key']}.",
                        'entity_id' => $cohort['curriculum_id'],
                    ];

                    continue;
                }

                $forecastByCohort[$cohort['key']] = [
                    'forecast' => $forecast,
                    'history' => $history,
                    'observation_count' => count($observations),
                    'strategy' => $strategy,
                ];
                $totalObservationCount += count($observations);
            }

            if ($forecastByCohort === []) {
                $this->completeWithoutHistory($generationRun, $predictionRun);

                return;
            }

            DB::transaction(function () use ($cohorts, $forecastByCohort, $predictionRun, $term): void {
                foreach ($cohorts as $cohort) {
                    $result = $forecastByCohort[$cohort['key']] ?? null;
                    if ($result === null) {
                        continue;
                    }

                    foreach ($cohort['placements'] as $placement) {
                        SectionDemandForecast::updateOrCreate(
                            [
                                'prediction_run_id' => $predictionRun->id,
                                'academic_term_id' => $term->id,
                                'curriculum_id' => $placement->curriculum_id,
                                'subject_id' => $placement->subject_id,
                                'year_level' => $placement->year_level,
                            ],
                            [
                                'program_id' => $placement->curriculum->program_id,
                                'historical_school_year' => $result['history']->schoolYear,
                                'historical_semester' => $result['history']->semester,
                                'historical_year_level' => $result['history']->yearLevel,
                                'predicted_demand' => $result['forecast']['predicted_demand'],
                                'suggested_section_count' => $result['forecast']['suggested_section_count'],
                                'confidence_lower' => $result['forecast']['confidence_lower'],
                                'confidence_upper' => $result['forecast']['confidence_upper'],
                                'rationale' => [
                                    'model_strategy' => $result['strategy'],
                                    'forecast_grain' => 'program_curriculum_year_cohort',
                                    'history_observation_count' => $result['observation_count'],
                                    'recommended_capacity' => 40,
                                ],
                            ],
                        );
                    }
                }
            });

            $hasRandomForest = collect($forecastByCohort)->contains(fn (array $res): bool => $res['strategy'] === 'random_forest');
            $overallStrategy = $hasRandomForest ? 'random_forest' : 'historical_baseline';

            $predictionRun->update([
                'status' => PredictionRunStatus::Succeeded,
                'model_version' => $serviceFallbackCount === count($forecastByCohort)
                    ? 'section-demand-local-baseline-v1'
                    : 'section-demand-rf-v2',
                'feature_schema_version' => 'v2',
                'metrics' => [
                    'forecast_count' => count($forecastByCohort),
                    'observation_count' => $totalObservationCount,
                    'strategy' => $overallStrategy,
                    'service_fallback_count' => $serviceFallbackCount,
                ],
                'completed_at' => now(),
            ]);
            $warnings = array_merge($warnings, $this->applyDemandForecastToDraft->execute($generationRun, $predictionRun));
            $warnings = array_merge($warnings, $this->facultyRecommendations->execute($generationRun));
            $generationRun->update([
                'status' => ScheduleGenerationStatus::Succeeded,
                'warnings' => array_values(array_unique($warnings, SORT_REGULAR)),
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $predictionRun->update([
                'status' => PredictionRunStatus::Failed,
                'error_summary' => 'Demand forecast generation failed.',
                'completed_at' => now(),
            ]);
            $generationRun->update([
                'status' => ScheduleGenerationStatus::Failed,
                'error_summary' => 'Demand forecast generation failed. Review the generation data and retry.',
                'completed_at' => now(),
            ]);
        }
    }

    /** @return Collection<int, CurriculumSubject> */
    private function currentTermPlacements(ScheduleGenerationRun $generationRun): Collection
    {
        return CurriculumSubject::query()
            ->with('curriculum.program')
            ->where(function ($query) use ($generationRun): void {
                $query->where('semester', $generationRun->academicTerm->semester)
                    ->orWhere('semester', 'like', '%'.$generationRun->academicTerm->semester.'%');
            })
            ->whereHas('curriculum.program', fn ($programs) => $programs
                ->where('college', $generationRun->college)
                ->where('code', '!=', 'TCP'))
            ->get();
    }

    /**
     * @param  Collection<int, CurriculumSubject>  $placements
     * @return Collection<int, array{key: string, curriculum_id: int, program_id: int, year_level: int, cohort_size: int, placements: Collection<int, CurriculumSubject>}>
     */
    private function eligibleCohorts(Collection $placements): Collection
    {
        return $placements
            ->groupBy(fn (CurriculumSubject $placement): string => $placement->curriculum_id.':'.$placement->year_level)
            ->map(function (Collection $cohortPlacements, string $key): array {
                /** @var CurriculumSubject $firstPlacement */
                $firstPlacement = $cohortPlacements->first();

                return [
                    'key' => $key,
                    'curriculum_id' => $firstPlacement->curriculum_id,
                    'program_id' => $firstPlacement->curriculum->program_id,
                    'year_level' => $firstPlacement->year_level,
                    'cohort_size' => StudentProfile::query()
                        ->where('curriculum_id', $firstPlacement->curriculum_id)
                        ->where('year_level', $firstPlacement->year_level)
                        ->whereIn('admission_status', [AdmissionStatus::Admitted->value, AdmissionStatus::Enrolled->value])
                        ->count(),
                    'placements' => $cohortPlacements,
                ];
            })
            ->filter(fn (array $cohort): bool => $cohort['cohort_size'] > 0)
            ->values();
    }

    /**
     * @param  array{program_id: int, curriculum_id: int, year_level: int}  $cohort
     * @return list<array{cohort_size: int, enrolled_count: int, section_count: int, offered_capacity: int, year_level: int, semester: string}>
     */
    private function realHistoricalObservations(array $cohort, HistoricalCohortReference $history): array
    {
        $rows = SectionDemandObservation::query()
            ->with('academicTerm')
            ->where('program_id', $cohort['program_id'])
            ->where('year_level', $history->yearLevel)
            ->where('source', 'derived_from_enrollments')
            ->whereHas('academicTerm', fn ($terms) => $terms
                ->where('semester', $history->semester)
                ->where('school_year', '<=', $history->schoolYear))
            ->get();

        return $rows
            ->sortBy(fn (SectionDemandObservation $row): string => $row->academicTerm->school_year)
            ->groupBy('academic_term_id')
            ->map(function (Collection $termRows): array {
                /** @var SectionDemandObservation $first */
                $first = $termRows->first();

                return [
                    'cohort_size' => (int) $termRows->max('cohort_size'),
                    'enrolled_count' => (int) $termRows->max('enrolled_count'),
                    'section_count' => (int) $termRows->max('section_count'),
                    'offered_capacity' => (int) $termRows->max('offered_capacity'),
                    'year_level' => $first->year_level,
                    'semester' => $first->academicTerm->semester,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{cohort_size: int, enrolled_count: int, section_count: int, offered_capacity: int, year_level: int, semester: string}>  $observations
     * @param  list<array{key: string, cohort_size: int, section_count: int, recommended_capacity: int, year_level: int, semester: string}>  $targets
     * @return array{model_version: string, feature_schema_version: string, strategy: string, forecasts: list<array{key: string, predicted_demand: float, confidence_lower: float, confidence_upper: float, suggested_section_count: int}>}
     */
    private function localBaselineResponse(array $observations, array $targets): array
    {
        $latest = $observations[array_key_last($observations)];

        return [
            'model_version' => 'section-demand-local-baseline-v1',
            'feature_schema_version' => 'v2',
            'strategy' => 'historical_baseline',
            'forecasts' => array_map(function (array $target) use ($latest): array {
                $enrollmentRate = $latest['enrolled_count'] / max($latest['cohort_size'], 1);
                $predictedDemand = round(max(0, $target['cohort_size'] * $enrollmentRate), 2);

                return [
                    'key' => $target['key'],
                    'predicted_demand' => $predictedDemand,
                    'confidence_lower' => $predictedDemand,
                    'confidence_upper' => $predictedDemand,
                    'suggested_section_count' => max(1, $latest['section_count']),
                ];
            }, $targets),
        ];
    }

    private function completeWithoutPlacements(ScheduleGenerationRun $generationRun, PredictionRun $predictionRun): void
    {
        $predictionRun->update([
            'status' => PredictionRunStatus::Succeeded,
            'metrics' => ['forecast_count' => 0],
            'completed_at' => now(),
        ]);
        $generationRun->update([
            'status' => ScheduleGenerationStatus::Succeeded,
            'warnings' => [[
                'type' => ScheduleGenerationWarningType::NoCurriculumSubjects->value,
                'message' => 'No current-term student cohorts were found for this college.',
                'entity_id' => null,
            ]],
            'completed_at' => now(),
        ]);
    }

    private function completeWithoutHistory(ScheduleGenerationRun $generationRun, PredictionRun $predictionRun): void
    {
        $predictionRun->update([
            'status' => PredictionRunStatus::Succeeded,
            'metrics' => [
                'forecast_count' => 0,
                'observation_count' => 0,
                'strategy' => 'insufficient_history',
            ],
            'completed_at' => now(),
        ]);
        $generationRun->update([
            'status' => ScheduleGenerationStatus::Succeeded,
            'warnings' => [[
                'type' => ScheduleGenerationWarningType::InsufficientHistory->value,
                'message' => 'Insufficient validated historical demand data for this college and term.',
                'entity_id' => null,
            ]],
            'completed_at' => now(),
        ]);
    }
}
