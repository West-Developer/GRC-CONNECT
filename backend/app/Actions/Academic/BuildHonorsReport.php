<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradePointAverage;
use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\SubjectGwaExclusionRule;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use Illuminate\Pagination\LengthAwarePaginator;

final class BuildHonorsReport
{
    /**
     * @param  array{college?: ?string, program_id?: ?int, year_level?: ?int}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function execute(AcademicTerm $term, array $filters, int $page, int $pageSize): LengthAwarePaginator
    {
        $enrollments = Enrollment::query()
            ->where('academic_term_id', $term->id)
            ->where('status', EnrollmentStatus::Enrolled->value)
            ->whereHas('student', function ($students) use ($filters): void {
                $students->where('is_demo_account', false)
                    ->when($filters['program_id'] ?? null, fn ($query, int $programId) => $query->where('program_id', $programId))
                    ->when($filters['year_level'] ?? null, fn ($query, int $yearLevel) => $query->where('year_level', $yearLevel))
                    ->when($filters['college'] ?? null, fn ($query, string $college) => $query->whereHas('program', fn ($programs) => $programs->where('college', $college)));
            })
            ->with([
                'student.user',
                'student.program',
                'student.grades' => fn ($grades) => $grades->where('academic_term_id', $term->id),
                'enrollmentSubjects' => fn ($subjects) => $subjects
                    ->where('status', EnrollmentSubjectStatus::Enrolled->value)
                    ->with('section.subject'),
            ])
            ->get();

        $rows = $enrollments->map(fn (Enrollment $enrollment): ?array => $this->qualifier($enrollment, $term))
            ->filter()
            ->sortBy('student_number')
            ->values();

        return new LengthAwarePaginator(
            $rows->forPage($page, $pageSize)->values(),
            $rows->count(),
            $pageSize,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );
    }

    /** @return ?array<string, mixed> */
    private function qualifier(Enrollment $enrollment, AcademicTerm $term): ?array
    {
        $subjects = $enrollment->enrollmentSubjects;
        if ($subjects->isEmpty()) {
            return null;
        }

        $grades = $enrollment->student->grades->keyBy('subject_id');
        $gpaRows = [];
        $excludedCount = 0;

        foreach ($subjects as $enrollmentSubject) {
            $subject = $enrollmentSubject->section->subject;
            $grade = $grades->get($subject->id);
            if (! $grade instanceof AcademicGrade || ! in_array($grade->status, [GradeStatus::Submitted, GradeStatus::Locked], true)) {
                return null;
            }

            $excluded = ! SubjectGwaExclusionRule::countsTowardGwa($subject->code);
            if ($excluded) {
                $excludedCount++;

                continue;
            }

            if ($grade->mark?->isNumeric()) {
                $gpaRows[] = ['mark' => $grade->mark, 'units' => $subject->units];

                continue;
            }

            if ($grade->mark?->isCompletion() && $subject->isCompletionOnly()) {
                $gpaRows[] = ['mark' => $grade->mark, 'units' => $subject->units];

                continue;
            }

            return null;
        }

        $gwaUnits = GradePointAverage::gpaUnits($gpaRows);
        $unrounded = GradePointAverage::unrounded($gpaRows);
        if ($unrounded === null || $unrounded < 1.0 || $unrounded > 1.5 || $gwaUnits < 16.0) {
            return null;
        }

        $student = $enrollment->student;

        return [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->user->name,
            'program_id' => $student->program->id,
            'program_code' => $student->program->code,
            'program_name' => $student->program->name,
            'college' => $student->program->college?->value,
            'year_level' => $student->year_level,
            'year_level_name' => self::formatYearLevel($student->year_level),
            'academic_term_id' => $term->id,
            'school_year' => $term->school_year,
            'semester' => $term->semester,
            'gwa' => GradePointAverage::compute($gpaRows),
            'gwa_units' => $gwaUnits,
            'excluded_subject_count' => $excludedCount,
        ];
    }

    public static function formatYearLevel(int $yearLevel): string
    {
        return match ($yearLevel) {
            1 => '1st Year',
            2 => '2nd Year',
            3 => '3rd Year',
            4 => '4th Year',
            default => "{$yearLevel}th Year",
        };
    }
}
