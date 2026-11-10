<?php

namespace App\Actions\Enrollment;

use App\Domain\Academic\GradeMark;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicGrade;
use App\Models\EnrollmentSubject;
use App\Models\StudentProfile;

/**
 * What the Registrar Head sees for one student on the subject-withdrawal page:
 * the subjects that can still be withdrawn (active subjects of an `enrolled`
 * enrollment in a term that is not closed) and the ones already withdrawn.
 */
final readonly class ListSubjectWithdrawals
{
    /**
     * @return array{withdrawable: list<array<string, mixed>>, withdrawn: list<array<string, mixed>>}
     */
    public function execute(StudentProfile $student): array
    {
        $withdrawable = EnrollmentSubject::query()
            ->whereHas('enrollment', fn ($query) => $query
                ->where('student_id', $student->id)
                ->where('status', EnrollmentStatus::Enrolled->value)
                ->whereHas('academicTerm', fn ($term) => $term->whereNotIn('status', [
                    AcademicTermStatus::SemesterClosed->value,
                    AcademicTermStatus::Archived->value,
                ])))
            ->where('status', '!=', EnrollmentSubjectStatus::Dropped->value)
            ->with(['enrollment.academicTerm', 'section.subject'])
            ->orderBy('id')
            ->get()
            ->map(static fn (EnrollmentSubject $subject): array => [
                'enrollment_subject_id' => $subject->id,
                'enrollment_id' => $subject->enrollment_id,
                'academic_term_id' => $subject->enrollment->academic_term_id,
                'term_label' => "{$subject->enrollment->academicTerm->school_year} · {$subject->enrollment->academicTerm->semester}",
                'subject_code' => $subject->section->subject->code,
                'subject_title' => $subject->section->subject->title,
                'units' => $subject->section->subject->units,
                'section_code' => $subject->section->section_code,
            ])
            ->values()
            ->all();

        $withdrawn = AcademicGrade::query()
            ->where('student_id', $student->id)
            ->where('mark', GradeMark::Withdrawn->value)
            ->with(['subject', 'academicTerm', 'encoder', 'section'])
            ->orderByDesc('locked_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AcademicGrade $grade): array => [
                'academic_grade_id' => $grade->id,
                'term_label' => "{$grade->academicTerm->school_year} · {$grade->academicTerm->semester}",
                'subject_code' => $grade->subject->code,
                'subject_title' => $grade->subject->title,
                'section_code' => $grade->section?->section_code,
                'reason' => $grade->remarks,
                'withdrawn_by' => $grade->encoder->name,
                'withdrawn_at' => $grade->locked_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ])
            ->values()
            ->all();

        return ['withdrawable' => $withdrawable, 'withdrawn' => $withdrawn];
    }
}
