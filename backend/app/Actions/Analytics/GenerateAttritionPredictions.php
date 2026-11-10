<?php

namespace App\Actions\Analytics;

use App\Domain\Academic\GradeMark;
use App\Domain\Analytics\PredictionRunStatus;
use App\Domain\Analytics\PredictionType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AttritionPrediction;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\PredictionRun;
use App\Models\StudentProfile;
use App\Services\Analytics\AttritionPredictionClient;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generates XGBoost-driven advisory attrition risk scores for enrolled students in a term.
 */
final class GenerateAttritionPredictions
{
    public function __construct(
        private readonly AttritionPredictionClient $predictionClient,
    ) {}

    public function execute(AcademicTerm $targetTerm): PredictionRun
    {
        $predictionRun = PredictionRun::create([
            'type' => PredictionType::Attrition,
            'academic_term_id' => $targetTerm->id,
            'model_version' => 'attrition-xgboost-v1',
            'feature_schema_version' => 'v1',
            'status' => PredictionRunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $activeStudents = StudentProfile::query()
                ->where('is_demo_account', false)
                ->whereHas('enrollments', fn ($query) => $query
                    ->where('academic_term_id', $targetTerm->id)
                    ->where('status', EnrollmentStatus::Enrolled->value))
                ->get();

            if ($activeStudents->isEmpty()) {
                $predictionRun->update([
                    'status' => PredictionRunStatus::Succeeded,
                    'metrics' => ['forecast_count' => 0, 'strategy' => 'no_target_students'],
                    'completed_at' => now(),
                ]);

                return $predictionRun;
            }

            // Build historical observations from past completed terms
            $observations = $this->buildHistoricalObservations($targetTerm);
            $targets = $this->buildTargets($activeStudents, $targetTerm);

            try {
                $response = $this->predictionClient->predict($observations, $targets);
                $strategy = (string) ($response['strategy'] ?? 'xgboost');
                $modelVersion = (string) ($response['model_version'] ?? 'attrition-xgboost-v1');
            } catch (Throwable $exception) {
                report($exception);
                $response = $this->fallbackBaselineResponse($targets);
                $strategy = 'heuristic_fallback';
                $modelVersion = 'attrition-heuristic-fallback-v1';
            }

            $now = now();
            $predictionRows = array_map(fn (array $prediction): array => [
                'prediction_run_id' => $predictionRun->id,
                'student_id' => $prediction['student_id'],
                'risk_probability' => $prediction['risk_probability'],
                'risk_band' => $prediction['risk_band'],
                'explanations' => json_encode($prediction['explanations']),
                'created_at' => $now,
                'updated_at' => $now,
            ], $response['predictions']);

            foreach (array_chunk($predictionRows, 500) as $chunk) {
                DB::table('attrition_predictions')->upsert(
                    $chunk,
                    ['prediction_run_id', 'student_id'],
                    ['risk_probability', 'risk_band', 'explanations', 'updated_at'],
                );
            }

            $predictionRun->update([
                'status' => PredictionRunStatus::Succeeded,
                'model_version' => $modelVersion,
                'metrics' => [
                    'training_observation_count' => count($observations),
                    'target_student_count' => count($targets),
                    'strategy' => $strategy,
                ],
                'completed_at' => now(),
            ]);

            return $predictionRun;
        } catch (Throwable $exception) {
            report($exception);
            $predictionRun->update([
                'status' => PredictionRunStatus::Failed,
                'error_summary' => 'Attrition risk prediction failed.',
                'completed_at' => now(),
            ]);

            return $predictionRun;
        }
    }

    /**
     * @return list<array{year_level: int, gpa: float, failed_units: int, dropped_units: int, is_irregular: int, consecutive_terms: int, attrited: int}>
     */
    private function buildHistoricalObservations(AcademicTerm $targetTerm): array
    {
        $pastTerms = AcademicTerm::query()
            ->where('id', '<', $targetTerm->id)
            ->orderBy('id')
            ->get();

        if ($pastTerms->isEmpty()) {
            return $this->syntheticSeedObservations();
        }

        $observations = [];
        for ($i = 0; $i < count($pastTerms) - 1; $i++) {
            $baseline = $pastTerms[$i];
            $comparison = $pastTerms[$i + 1];

            $enrolled = StudentProfile::query()
                ->where('is_demo_account', false)
                ->whereHas('enrollments', fn ($q) => $q
                    ->where('academic_term_id', $baseline->id)
                    ->where('status', EnrollmentStatus::Enrolled->value))
                ->with(['enrollments', 'grades'])
                ->get();

            $retainedIds = Enrollment::query()
                ->where('academic_term_id', $comparison->id)
                ->where('status', EnrollmentStatus::Enrolled->value)
                ->whereIn('student_id', $enrolled->modelKeys())
                ->pluck('student_id')
                ->flip();

            foreach ($enrolled as $student) {
                $grades = $student->grades->where('academic_term_id', $baseline->id);
                $numericGrades = $grades->whereNotNull('final_grade')->map(fn ($g) => (float) $g->final_grade);
                $gpa = $numericGrades->isEmpty() ? 2.25 : round((float) $numericGrades->avg(), 2);
                $failedUnits = $grades->whereIn('mark', [GradeMark::Failed, GradeMark::NotComplete, GradeMark::Incomplete])->count() * 3;
                $droppedUnits = $grades->where('mark', GradeMark::Dropped)->count() * 3;
                $isIrregular = $student->academic_standing?->value === 'irregular' || $failedUnits > 0 ? 1 : 0;
                $consecutiveTerms = max(1, $i + 1);
                $attrited = $retainedIds->has($student->id) ? 0 : 1;

                $observations[] = [
                    'year_level' => min(4, max(1, (int) $student->year_level)),
                    'gpa' => min(5.0, max(1.0, (float) $gpa)),
                    'failed_units' => max(0, $failedUnits),
                    'dropped_units' => max(0, $droppedUnits),
                    'is_irregular' => $isIrregular,
                    'consecutive_terms' => $consecutiveTerms,
                    'attrited' => $attrited,
                ];
            }
        }

        return $observations ?: $this->syntheticSeedObservations();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StudentProfile>  $students
     * @return list<array{student_id: int, year_level: int, gpa: float, failed_units: int, dropped_units: int, is_irregular: int, consecutive_terms: int}>
     */
    private function buildTargets(\Illuminate\Support\Collection $students, AcademicTerm $targetTerm): array
    {
        $targets = [];
        foreach ($students as $student) {
            $grades = AcademicGrade::query()
                ->where('student_id', $student->id)
                ->where('academic_term_id', '<=', $targetTerm->id)
                ->get();

            $numericGrades = $grades->whereNotNull('final_grade')->map(fn ($g) => (float) $g->final_grade);
            $gpa = $numericGrades->isEmpty() ? 2.0 : round((float) $numericGrades->avg(), 2);
            $failedUnits = $grades->whereIn('mark', [GradeMark::Failed, GradeMark::NotComplete, GradeMark::Incomplete])->count() * 3;
            $droppedUnits = $grades->where('mark', GradeMark::Dropped)->count() * 3;
            $isIrregular = $student->academic_standing?->value === 'irregular' || $failedUnits > 0 ? 1 : 0;
            $consecutiveTerms = max(1, (int) $student->year_level * 2);

            $targets[] = [
                'student_id' => $student->id,
                'year_level' => min(4, max(1, (int) $student->year_level)),
                'gpa' => min(5.0, max(1.0, (float) $gpa)),
                'failed_units' => max(0, $failedUnits),
                'dropped_units' => max(0, $droppedUnits),
                'is_irregular' => $isIrregular,
                'consecutive_terms' => $consecutiveTerms,
            ];
        }

        return $targets;
    }

    /**
     * @return list<array{year_level: int, gpa: float, failed_units: int, dropped_units: int, is_irregular: int, consecutive_terms: int, attrited: int}>
     */
    private function syntheticSeedObservations(): array
    {
        return [
            ['year_level' => 1, 'gpa' => 1.5, 'failed_units' => 0, 'dropped_units' => 0, 'is_irregular' => 0, 'consecutive_terms' => 2, 'attrited' => 0],
            ['year_level' => 1, 'gpa' => 2.0, 'failed_units' => 0, 'dropped_units' => 0, 'is_irregular' => 0, 'consecutive_terms' => 1, 'attrited' => 0],
            ['year_level' => 2, 'gpa' => 1.75, 'failed_units' => 0, 'dropped_units' => 0, 'is_irregular' => 0, 'consecutive_terms' => 3, 'attrited' => 0],
            ['year_level' => 2, 'gpa' => 2.25, 'failed_units' => 3, 'dropped_units' => 0, 'is_irregular' => 0, 'consecutive_terms' => 3, 'attrited' => 0],
            ['year_level' => 3, 'gpa' => 2.0, 'failed_units' => 0, 'dropped_units' => 0, 'is_irregular' => 0, 'consecutive_terms' => 5, 'attrited' => 0],
            ['year_level' => 1, 'gpa' => 3.75, 'failed_units' => 9, 'dropped_units' => 3, 'is_irregular' => 1, 'consecutive_terms' => 1, 'attrited' => 1],
            ['year_level' => 1, 'gpa' => 4.0, 'failed_units' => 12, 'dropped_units' => 6, 'is_irregular' => 1, 'consecutive_terms' => 1, 'attrited' => 1],
            ['year_level' => 2, 'gpa' => 3.5, 'failed_units' => 6, 'dropped_units' => 3, 'is_irregular' => 1, 'consecutive_terms' => 2, 'attrited' => 1],
            ['year_level' => 2, 'gpa' => 3.75, 'failed_units' => 9, 'dropped_units' => 3, 'is_irregular' => 1, 'consecutive_terms' => 3, 'attrited' => 1],
            ['year_level' => 3, 'gpa' => 3.5, 'failed_units' => 6, 'dropped_units' => 3, 'is_irregular' => 1, 'consecutive_terms' => 4, 'attrited' => 1],
        ];
    }

    /**
     * @param  list<array{student_id: int, year_level: int, gpa: float, failed_units: int, dropped_units: int, is_irregular: int, consecutive_terms: int}>  $targets
     * @return array{model_version: string, feature_schema_version: string, strategy: string, predictions: list<array{student_id: int, risk_probability: float, risk_band: string, explanations: list<string>}>}
     */
    private function fallbackBaselineResponse(array $targets): array
    {
        return [
            'model_version' => 'attrition-heuristic-fallback-v1',
            'feature_schema_version' => 'v1',
            'strategy' => 'heuristic_fallback',
            'predictions' => array_map(function (array $target): array {
                $prob = 0.05 + min(0.40, $target['failed_units'] * 0.06) + min(0.25, $target['dropped_units'] * 0.05) + ($target['gpa'] >= 3.0 ? 0.20 : 0.0) + ($target['is_irregular'] ? 0.10 : 0.0);
                $probBounded = round(min(0.95, max(0.02, $prob)), 4);
                $band = $probBounded >= 0.80 ? 'critical' : ($probBounded >= 0.50 ? 'high' : ($probBounded >= 0.20 ? 'medium' : 'low'));
                $explanations = [];
                if ($target['failed_units'] > 0) {
                    $explanations[] = "Has {$target['failed_units']} failed unit(s) requiring remediation.";
                }
                if ($target['dropped_units'] > 0) {
                    $explanations[] = "Has {$target['dropped_units']} dropped unit(s).";
                }
                if ($target['gpa'] >= 3.0) {
                    $explanations[] = "Low GPA standing ({$target['gpa']}).";
                }
                if (! $explanations) {
                    $explanations[] = 'Normal academic standing with stable enrollment history.';
                }

                return [
                    'student_id' => $target['student_id'],
                    'risk_probability' => $probBounded,
                    'risk_band' => $band,
                    'explanations' => $explanations,
                ];
            }, $targets),
        ];
    }
}
