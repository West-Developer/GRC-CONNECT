<?php

namespace App\Actions\Academic;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicGrade;
use App\Models\EnrollmentSubject;
use App\Models\Section;

final readonly class BuildSectionGradeSheet
{
    public function __construct(private BuildSectionGradeSummary $summaryBuilder) {}

    /**
     * @return array{
     *     section: Section,
     *     rows: list<array{roster_entry: EnrollmentSubject, grade: ?AcademicGrade}>
     * }
     */
    public function execute(Section $section): array
    {
        $section->loadMissing(['academicTerm', 'subject']);
        $section->setAttribute('grade_progress', $this->summaryBuilder->execute($section));

        $roster = $section->enrollmentSubjects()
            ->where('enrollment_subjects.status', EnrollmentSubjectStatus::Enrolled->value)
            ->whereHas('enrollment', fn ($query) => $query->where('enrollments.status', EnrollmentStatus::Enrolled->value))
            ->with(['enrollment.student.user'])
            ->get()
            ->sortBy(fn (EnrollmentSubject $entry): string => mb_strtolower(
                $entry->enrollment->student->user->name.' '.$entry->enrollment->student->student_number,
            ))
            ->values();

        $gradesByStudent = AcademicGrade::query()
            ->where('section_id', $section->id)
            ->where('subject_id', $section->subject_id)
            ->where('academic_term_id', $section->academic_term_id)
            ->whereIn('student_id', $roster->pluck('enrollment.student_id'))
            ->get()
            ->keyBy('student_id');

        $rows = $roster->map(fn (EnrollmentSubject $entry): array => [
            'roster_entry' => $entry,
            'grade' => $gradesByStudent->get($entry->enrollment->student_id),
        ])->all();

        return ['section' => $section, 'rows' => $rows];
    }
}
