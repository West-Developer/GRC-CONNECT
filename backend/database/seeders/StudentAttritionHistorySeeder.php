<?php

namespace Database\Seeders;

use App\Actions\Analytics\DeriveSectionDemandObservations;
use App\Actions\Analytics\GenerateAttritionPredictions;
use App\Domain\Academic\GradeMark;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\StudentProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds realistic historical drop-out and stop-out records across terms from 2023-2024 to 2026-2027.
 */
final class StudentAttritionHistorySeeder extends Seeder
{
    public function run(): void
    {
        $terms = AcademicTerm::query()->orderBy('id')->get();
        if ($terms->isEmpty()) {
            return;
        }

        $stoppedStudentIds = [];

        foreach ($terms as $term) {
            // Find active enrollments in this term
            $enrollments = Enrollment::query()
                ->where('academic_term_id', $term->id)
                ->where('status', EnrollmentStatus::Enrolled->value)
                ->whereNotIn('student_id', $stoppedStudentIds)
                ->get();

            if ($enrollments->isEmpty()) {
                continue;
            }

            // Group by program and year level for proportional representation
            $studentsById = StudentProfile::query()
                ->whereIn('id', $enrollments->pluck('student_id'))
                ->get()
                ->keyBy('id');

            $cohortGroups = [];
            foreach ($enrollments as $enrollment) {
                $student = $studentsById->get($enrollment->student_id);
                if ($student === null) {
                    continue;
                }
                $key = $student->program_id.'|'.$student->year_level;
                $cohortGroups[$key][] = ['enrollment' => $enrollment, 'student' => $student];
            }

            foreach ($cohortGroups as $cohortKey => $members) {
                // Rate: ~5% stop-outs and ~2% in-term withdrawals
                $count = count($members);
                $withdrawCount = max(1, (int) round($count * 0.02));
                $stopOutCount = max(1, (int) round($count * 0.05));

                // Deterministic sort by hash
                usort($members, fn ($a, $b) => hash('crc32b', $term->id.'|'.$a['student']->student_number) <=> hash('crc32b', $term->id.'|'.$b['student']->student_number));

                // 1. In-term withdrawals
                $withdrawnSubset = array_slice($members, 0, $withdrawCount);
                foreach ($withdrawnSubset as $item) {
                    /** @var Enrollment $enrollment */
                    $enrollment = $item['enrollment'];
                    /** @var StudentProfile $student */
                    $student = $item['student'];

                    $enrollment->update([
                        'status' => EnrollmentStatus::Withdrawn->value,
                    ]);

                    EnrollmentSubject::query()
                        ->where('enrollment_id', $enrollment->id)
                        ->update(['status' => EnrollmentSubjectStatus::Dropped->value]);

                    AcademicGrade::query()
                        ->where('student_id', $student->id)
                        ->where('academic_term_id', $term->id)
                        ->update([
                            'mark' => GradeMark::Dropped->value,
                            'final_grade' => null,
                        ]);

                    // Remove future enrollments
                    Enrollment::query()
                        ->where('student_id', $student->id)
                        ->where('academic_term_id', '>', $term->id)
                        ->delete();

                    $student->update([
                        'admission_status' => AdmissionStatus::Withdrawn->value,
                        'academic_standing' => AcademicStanding::Warning->value,
                    ]);

                    $stoppedStudentIds[] = $student->id;
                }

                // 2. Term-end stop-outs (failed subjects and didn't re-enroll next term)
                $stopOutSubset = array_slice($members, $withdrawCount, $stopOutCount);
                foreach ($stopOutSubset as $item) {
                    /** @var Enrollment $enrollment */
                    $enrollment = $item['enrollment'];
                    /** @var StudentProfile $student */
                    $student = $item['student'];

                    // Mark 2-3 subjects as Failed/Incomplete
                    $grades = AcademicGrade::query()
                        ->where('student_id', $student->id)
                        ->where('academic_term_id', $term->id)
                        ->take(3)
                        ->get();

                    foreach ($grades as $grade) {
                        $grade->update([
                            'mark' => GradeMark::Failed->value,
                            'final_grade' => '5.00',
                        ]);
                    }

                    // Remove future enrollments so student is non-retained in subsequent terms
                    Enrollment::query()
                        ->where('student_id', $student->id)
                        ->where('academic_term_id', '>', $term->id)
                        ->delete();

                    $student->update([
                        'admission_status' => AdmissionStatus::Withdrawn->value,
                        'academic_standing' => AcademicStanding::Probation->value,
                    ]);

                    $stoppedStudentIds[] = $student->id;
                }
            }
        }

        // Re-derive section demand observations so counts match the real enrollment history
        app(DeriveSectionDemandObservations::class)->execute();

        // Run XGBoost Attrition predictions for terms
        $predictionAction = app(GenerateAttritionPredictions::class);
        foreach ($terms as $term) {
            try {
                $predictionAction->execute($term);
            } catch (\Throwable $e) {
                // If ML service is offline or term has no targets, action handles gracefully
            }
        }
    }
}
