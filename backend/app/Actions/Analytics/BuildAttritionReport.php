<?php

namespace App\Actions\Analytics;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * Factual, aggregate-only persistence report. Its baseline is official
 * enrollment, never a predictive-risk table or a student's private profile.
 *
 * Attrition means students who will not continue (stakeholder Doc 14, S19). Of
 * the students enrolled in the baseline term:
 *   retained  enrolled in the comparison term;
 *   attrited  withdrew from the school, or finished the baseline term and did
 *             not enroll in the comparison term although its enrollment window
 *             has closed;
 *   left out  graduates (nothing to continue), and students still deciding: no
 *             enrollment yet while the comparison window is open, or an
 *             enrollment in progress. They are reported as `undecided_count`,
 *             never counted as attrition.
 */
final class BuildAttritionReport
{
    /**
     * @param  array{college?: ?string, program_id?: ?int, year_level?: ?int}  $filters
     * @return array{baseline_term: array<string, mixed>, comparison_term: array<string, mixed>, generated_at: string, summary: array<string, int|float>, groups: array<string, list<array<string, int|float|string|null>>}
     */
    public function execute(AcademicTerm $baselineTerm, AcademicTerm $comparisonTerm, array $filters): array
    {
        $baselineStudents = StudentProfile::query()
            ->where('is_demo_account', false)
            ->whereHas('enrollments', fn ($query) => $query
                ->where('academic_term_id', $baselineTerm->id)
                ->where('status', EnrollmentStatus::Enrolled->value))
            ->with('program')
            ->when($filters['program_id'] ?? null, fn ($query, int $programId) => $query->where('program_id', $programId))
            ->when($filters['year_level'] ?? null, fn ($query, int $yearLevel) => $query->where('year_level', $yearLevel))
            ->when($filters['college'] ?? null, fn ($query, string $college) => $query->whereHas('program', fn ($programs) => $programs->where('college', $college)))
            ->get();

        $baselineIds = $baselineStudents->modelKeys();
        $retainedIds = Enrollment::query()
            ->where('academic_term_id', $comparisonTerm->id)
            ->where('status', EnrollmentStatus::Enrolled->value)
            ->whereIn('student_id', $baselineIds)
            ->distinct()
            ->pluck('student_id')
            ->flip();
        $inProgressIds = Enrollment::query()
            ->where('academic_term_id', $comparisonTerm->id)
            ->whereIn('status', array_map(fn (EnrollmentStatus $status): string => $status->value, [
                EnrollmentStatus::Draft,
                EnrollmentStatus::PendingProgramHeadApproval,
                EnrollmentStatus::PendingStudentReview,
                EnrollmentStatus::PendingRegistrarApproval,
                EnrollmentStatus::PendingPayment,
            ]))
            ->whereIn('student_id', $baselineIds)
            ->distinct()
            ->pluck('student_id')
            ->flip();
        $withdrawnIds = Enrollment::query()
            ->whereIn('academic_term_id', [$baselineTerm->id, $comparisonTerm->id])
            ->where('status', EnrollmentStatus::Withdrawn->value)
            ->whereIn('student_id', $baselineIds)
            ->distinct()
            ->pluck('student_id')
            ->flip();
        $windowClosed = self::enrollmentWindowClosed($comparisonTerm);

        $undecided = 0;
        $graduated = 0;
        $rows = collect();
        foreach ($baselineStudents as $student) {
            if ($student->graduation_school_year !== null || $student->admission_status === AdmissionStatus::Graduated) {
                $graduated++;

                continue;
            }

            $retained = $retainedIds->has($student->id);
            $withdrew = $student->admission_status === AdmissionStatus::Withdrawn || $withdrawnIds->has($student->id);

            if (! $retained && ! $withdrew && (! $windowClosed || $inProgressIds->has($student->id))) {
                $undecided++;

                continue;
            }

            $rows->push([
                'college' => $student->program->college?->value,
                'program_id' => $student->program->id,
                'program_code' => $student->program->code,
                'program_name' => $student->program->name,
                'year_level' => $student->year_level,
                'retained' => $retained,
            ]);
        }

        return [
            'baseline_term' => self::term($baselineTerm),
            'comparison_term' => self::term($comparisonTerm),
            'generated_at' => now()->toIso8601String(),
            'summary' => self::metrics($rows) + ['undecided_count' => $undecided, 'graduated_count' => $graduated],
            'groups' => [
                'colleges' => $rows->groupBy(fn (array $row): string => (string) ($row['college'] ?? 'Unassigned'))
                    ->map(fn (Collection $group): array => array_merge(['college' => $group->first()['college']], self::metrics($group)))
                    ->sortBy('college')->values()->all(),
                'programs' => $rows->groupBy(fn (array $row): string => (string) $row['program_id'])
                    ->map(fn (Collection $group): array => array_merge([
                        'college' => $group->first()['college'],
                        'program_id' => $group->first()['program_id'],
                        'program_code' => $group->first()['program_code'],
                        'program_name' => $group->first()['program_name'],
                    ], self::metrics($group)))
                    ->sortBy('program_code')->values()->all(),
                'year_levels' => $rows->groupBy(fn (array $row): string => (string) $row['year_level'])
                    ->map(fn (Collection $group): array => array_merge(['year_level' => $group->first()['year_level']], self::metrics($group)))
                    ->sortBy('year_level')->values()->all(),
            ],
        ];
    }

    /**
     * Whether students have had their chance to enroll for this term: the
     * enrollment window has ended, else the add/drop deadline has passed, else
     * the term itself is closed. Without any of those the window is treated as
     * still open, so nobody is called a dropout early.
     */
    private static function enrollmentWindowClosed(AcademicTerm $term): bool
    {
        if ($term->enrollment_closes_at !== null) {
            return $term->enrollment_closes_at->isPast();
        }

        if ($term->add_drop_deadline_at !== null) {
            return $term->add_drop_deadline_at->isPast();
        }

        return in_array($term->status, [AcademicTermStatus::SemesterClosed, AcademicTermStatus::Archived], true);
    }

    /** @return array<string, string|int> */
    private static function term(AcademicTerm $term): array
    {
        return ['id' => $term->id, 'school_year' => $term->school_year, 'semester' => $term->semester];
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array{baseline_count: int, retained_count: int, attrited_count: int, attrition_rate: float} */
    private static function metrics(Collection $rows): array
    {
        $baseline = $rows->count();
        $retained = $rows->where('retained', true)->count();
        $attrited = $baseline - $retained;

        return [
            'baseline_count' => $baseline,
            'retained_count' => $retained,
            'attrited_count' => $attrited,
            'attrition_rate' => $baseline === 0 ? 0.0 : round(($attrited / $baseline) * 100, 2),
        ];
    }
}
