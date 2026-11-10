<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradeStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicGrade;
use App\Models\Section;

final readonly class BuildSectionGradeSummary
{
    /**
     * @return array{
     *     enrolled_count: int,
     *     recorded_count: int,
     *     submitted_count: int,
     *     locked_count: int,
     *     missing_count: int,
     *     state: string
     * }
     */
    public function execute(Section $section): array
    {
        $studentIds = $section->enrollmentSubjects()
            ->where('enrollment_subjects.status', EnrollmentSubjectStatus::Enrolled->value)
            ->whereHas('enrollment', fn ($query) => $query->where('enrollments.status', EnrollmentStatus::Enrolled->value))
            ->join('enrollments', 'enrollments.id', '=', 'enrollment_subjects.enrollment_id')
            ->pluck('enrollments.student_id');

        $enrolledCount = $studentIds->count();
        $gradeQuery = AcademicGrade::query()
            ->where('section_id', $section->id)
            ->where('subject_id', $section->subject_id)
            ->where('academic_term_id', $section->academic_term_id)
            ->whereIn('student_id', $studentIds)
            ->whereNotNull('mark');

        $recordedCount = (clone $gradeQuery)->count();
        $submittedCount = (clone $gradeQuery)->where('status', GradeStatus::Submitted->value)->count();
        $lockedCount = (clone $gradeQuery)->where('status', GradeStatus::Locked->value)->count();
        $missingCount = max(0, $enrolledCount - $recordedCount);

        $state = match (true) {
            $enrolledCount > 0 && $lockedCount === $enrolledCount => 'locked',
            $enrolledCount > 0 && $submittedCount + $lockedCount === $enrolledCount => 'submitted',
            $enrolledCount > 0 && $missingCount === 0 => 'ready',
            $recordedCount > 0 => 'in_progress',
            default => 'not_started',
        };

        return [
            'enrolled_count' => $enrolledCount,
            'recorded_count' => $recordedCount,
            'submitted_count' => $submittedCount,
            'locked_count' => $lockedCount,
            'missing_count' => $missingCount,
            'state' => $state,
        ];
    }
}
