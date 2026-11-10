<?php

namespace App\Actions\Academic;

use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicGrade;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveSectionGradeDrafts
{
    public function __construct(
        private RecordAcademicGrade $recorder,
        private UpdateAcademicGrade $updater,
    ) {}

    /**
     * @param  list<array{student_id: int, mark: string, remarks?: ?string}>  $rows
     */
    public function execute(
        Section $section,
        array $rows,
        User $actor,
        AuditRequestContext $context,
    ): void {
        DB::transaction(function () use ($section, $rows, $actor, $context): void {
            $lockedSection = Section::query()->whereKey($section->id)->lockForUpdate()->firstOrFail();

            if ($lockedSection->professor_id !== $actor->id) {
                throw ValidationException::withMessages([
                    'section' => 'This section is not assigned to you.',
                ]);
            }

            $rosterStudentIds = $lockedSection->enrollmentSubjects()
                ->where('enrollment_subjects.status', EnrollmentSubjectStatus::Enrolled->value)
                ->whereHas('enrollment', fn ($query) => $query->where('enrollments.status', EnrollmentStatus::Enrolled->value))
                ->join('enrollments', 'enrollments.id', '=', 'enrollment_subjects.enrollment_id')
                ->lockForUpdate()
                ->pluck('enrollments.student_id')
                ->map(fn ($studentId): int => (int) $studentId)
                ->all();

            foreach ($rows as $index => $row) {
                $studentId = (int) $row['student_id'];

                if (! in_array($studentId, $rosterStudentIds, true)) {
                    throw ValidationException::withMessages([
                        "grades.{$index}.student_id" => 'The student is not enrolled in this section.',
                    ]);
                }

                $grade = AcademicGrade::query()
                    ->where('student_id', $studentId)
                    ->where('subject_id', $lockedSection->subject_id)
                    ->where('academic_term_id', $lockedSection->academic_term_id)
                    ->lockForUpdate()
                    ->first();

                if ($grade !== null && $grade->section_id !== $lockedSection->id) {
                    throw ValidationException::withMessages([
                        "grades.{$index}.student_id" => 'The existing grade belongs to a different section.',
                    ]);
                }

                $validated = ['mark' => $row['mark']];

                if (array_key_exists('remarks', $row)) {
                    $validated['remarks'] = $row['remarks'];
                }

                if ($grade === null) {
                    $this->recorder->execute([
                        'student_id' => $studentId,
                        'subject_id' => $lockedSection->subject_id,
                        'section_id' => $lockedSection->id,
                        'academic_term_id' => $lockedSection->academic_term_id,
                        ...$validated,
                    ], $actor, $context);

                    continue;
                }

                $this->updater->execute($grade, $validated, $actor, $context);
            }
        });
    }
}
