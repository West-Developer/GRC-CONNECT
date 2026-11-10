<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicGrade;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SubmitSectionGrades
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(
        Section $section,
        User $actor,
        AuditRequestContext $context,
    ): void {
        DB::transaction(function () use ($section, $actor, $context): void {
            $lockedSection = Section::query()->whereKey($section->id)->lockForUpdate()->firstOrFail();

            if ($lockedSection->professor_id !== $actor->id) {
                throw ValidationException::withMessages([
                    'section' => 'This section is not assigned to you.',
                ]);
            }

            $studentIds = $lockedSection->enrollmentSubjects()
                ->where('enrollment_subjects.status', EnrollmentSubjectStatus::Enrolled->value)
                ->whereHas('enrollment', fn ($query) => $query->where('enrollments.status', EnrollmentStatus::Enrolled->value))
                ->join('enrollments', 'enrollments.id', '=', 'enrollment_subjects.enrollment_id')
                ->lockForUpdate()
                ->pluck('enrollments.student_id')
                ->map(fn ($studentId): int => (int) $studentId)
                ->all();

            if ($studentIds === []) {
                throw ValidationException::withMessages([
                    'grades' => 'This section has no enrolled students to submit.',
                ]);
            }

            $grades = AcademicGrade::query()
                ->where('section_id', $lockedSection->id)
                ->where('subject_id', $lockedSection->subject_id)
                ->where('academic_term_id', $lockedSection->academic_term_id)
                ->whereIn('student_id', $studentIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('student_id');

            foreach ($studentIds as $studentId) {
                $grade = $grades->get($studentId);

                if (! $grade instanceof AcademicGrade || $grade->mark === null) {
                    throw ValidationException::withMessages([
                        'grades' => 'Every enrolled student must have a valid grade before final submission.',
                    ]);
                }
            }

            $submittedAt = now();

            foreach ($grades as $grade) {
                if ($grade->status !== GradeStatus::Draft) {
                    continue;
                }

                $beforeValues = self::snapshot($grade);
                $grade->update([
                    'status' => GradeStatus::Submitted,
                    'submitted_at' => $submittedAt,
                ]);
                $grade->refresh();

                $this->auditRecorder->record(
                    $actor,
                    AuditAction::ACADEMIC_GRADE_SUBMITTED,
                    AuditableType::ACADEMIC_GRADE,
                    $grade->id,
                    $beforeValues,
                    self::snapshot($grade),
                    null,
                    $context,
                );
            }
        });
    }

    /**
     * @return array{status: string, mark: ?string, final_grade: ?string, remarks: ?string, submitted_at: ?string, locked_at: ?string}
     */
    private static function snapshot(AcademicGrade $grade): array
    {
        return [
            'status' => $grade->status->value,
            'mark' => $grade->mark?->value,
            'final_grade' => $grade->final_grade,
            'remarks' => $grade->remarks,
            'submitted_at' => $grade->submitted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'locked_at' => $grade->locked_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
