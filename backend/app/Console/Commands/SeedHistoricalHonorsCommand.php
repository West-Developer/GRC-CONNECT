<?php

namespace App\Console\Commands;

use App\Actions\Academic\BuildHonorsReport;
use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\SubjectGwaExclusionRule;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SeedHistoricalHonorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'honors:seed-historical
                            {--term= : Specific Academic Term ID to seed}
                            {--percentage=4.0 : Target percentage of honor students per program cohort}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seeds realistic honor students (1.00–1.50 GWA) for completed academic terms';

    /**
     * Execute the console command.
     */
    public function handle(BuildHonorsReport $reportBuilder): int
    {
        $termIdOption = $this->option('term');
        $percentage = (float) ($this->option('percentage') ?: 4.0);

        if ($termIdOption !== null) {
            $terms = AcademicTerm::where('id', (int) $termIdOption)->get();
        } else {
            $terms = AcademicTerm::where('status', '!=', 'semester_ongoing')
                ->where('status', '!=', 'draft')
                ->orderBy('id')
                ->get();
        }

        if ($terms->isEmpty()) {
            $this->warn('No matching academic terms found for honors seeding.');

            return self::SUCCESS;
        }

        $highMarks = [
            GradeMark::Excellent,
            GradeMark::HighDistinction,
            GradeMark::WithDistinction,
        ];

        $totalSeededAllTerms = 0;

        foreach ($terms as $term) {
            $this->info("Processing Term {$term->id} ({$term->school_year} {$term->semester})...");

            $enrollments = Enrollment::query()
                ->where('academic_term_id', $term->id)
                ->where('status', EnrollmentStatus::Enrolled->value)
                ->whereHas('student', fn ($query) => $query->where('is_demo_account', false))
                ->with([
                    'student.user',
                    'student.program',
                    'enrollmentSubjects' => fn ($query) => $query
                        ->where('status', EnrollmentSubjectStatus::Enrolled->value)
                        ->with('section.subject'),
                ])
                ->get();

            if ($enrollments->isEmpty()) {
                $this->line("  No enrolled students in Term {$term->id}. Skipping.");

                continue;
            }

            // Group enrollments by program_id + year_level
            $grouped = $enrollments->groupBy(fn (Enrollment $e) => $e->student->program_id.'|'.$e->student->year_level);

            $selectedStudentIds = [];

            foreach ($grouped as $key => $cohortEnrollments) {
                $cohortCount = $cohortEnrollments->count();
                $targetCount = max(1, (int) round($cohortCount * ($percentage / 100.0)));

                // Deterministically rank students within cohort
                $sorted = $cohortEnrollments->sortBy(function (Enrollment $e) use ($term) {
                    return sprintf('%u', crc32($e->student->student_number.':honors:'.$term->id));
                })->values();

                $selected = $sorted->take($targetCount);
                foreach ($selected as $e) {
                    $selectedStudentIds[] = $e->student_id;
                }
            }

            $selectedStudentIds = array_unique($selectedStudentIds);
            $this->line(sprintf('  Selected %d honor student candidates out of %d enrolled students (%.1f%% target).', count($selectedStudentIds), $enrollments->count(), $percentage));

            $now = now();
            $termSeededCount = 0;

            DB::transaction(function () use ($enrollments, $selectedStudentIds, $highMarks, $term, $now, &$termSeededCount) {
                foreach ($enrollments as $enrollment) {
                    if (! in_array($enrollment->student_id, $selectedStudentIds, true)) {
                        continue;
                    }

                    $student = $enrollment->student;
                    $subjects = $enrollment->enrollmentSubjects;

                    if ($subjects->isEmpty()) {
                        continue;
                    }

                    $updatedForStudent = false;

                    foreach ($subjects as $es) {
                        $section = $es->section;
                        $subject = $section->subject;

                        if (! SubjectGwaExclusionRule::countsTowardGwa($subject->code)) {
                            // Ensure excluded subjects (PE/NSTP) have a passing non-interfering mark
                            AcademicGrade::updateOrCreate(
                                [
                                    'student_id' => $student->id,
                                    'subject_id' => $subject->id,
                                    'academic_term_id' => $term->id,
                                ],
                                [
                                    'section_id' => $section->id,
                                    'mark' => GradeMark::Passed->value,
                                    'final_grade' => 3.00,
                                    'status' => GradeStatus::Locked->value,
                                    'encoded_by' => $section->professor_id,
                                    'submitted_at' => $term->updated_at ?? $now,
                                    'locked_at' => $term->updated_at ?? $now,
                                ]
                            );

                            continue;
                        }

                        // Hash for deterministic mark choice (1.00, 1.25, 1.50)
                        $markHash = (int) sprintf('%u', crc32($student->student_number.':'.$subject->id.':'.$term->id));
                        $markChoice = $highMarks[$markHash % count($highMarks)];

                        AcademicGrade::updateOrCreate(
                            [
                                'student_id' => $student->id,
                                'subject_id' => $subject->id,
                                'academic_term_id' => $term->id,
                            ],
                            [
                                'section_id' => $section->id,
                                'mark' => $markChoice->value,
                                'final_grade' => (float) $markChoice->value,
                                'status' => GradeStatus::Locked->value,
                                'encoded_by' => $section->professor_id,
                                'submitted_at' => $term->updated_at ?? $now,
                                'locked_at' => $term->updated_at ?? $now,
                            ]
                        );

                        $updatedForStudent = true;
                    }

                    if ($updatedForStudent) {
                        $termSeededCount++;
                    }
                }
            });

            $reportResult = $reportBuilder->execute($term, [], 1, 1000);
            $totalQualifiers = $reportResult->total();

            $this->info(sprintf('  Term %d (%s %s) finished: Seeded %d students. Verified Honors Qualifiers = %d.', $term->id, $term->school_year, $term->semester, $termSeededCount, $totalQualifiers));
            $totalSeededAllTerms += $totalQualifiers;
        }

        $this->info("Successfully seeded historical honors! Total qualifiers across processed terms: {$totalSeededAllTerms}.");

        return self::SUCCESS;
    }
}

