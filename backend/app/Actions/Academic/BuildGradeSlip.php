<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradePointAverage;
use App\Domain\Academic\GradeSlip;
use App\Domain\Academic\SubjectGwaExclusionRule;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\StudentProfile;

/**
 * Builds one term's printable grade slip in a single query.
 */
final readonly class BuildGradeSlip
{
    public function execute(StudentProfile $student, AcademicTerm $term): GradeSlip
    {
        $grades = AcademicGrade::query()
            ->where('student_id', $student->id)
            ->where('academic_term_id', $term->id)
            ->with(['subject', 'section.professor'])
            ->orderBy('id')
            ->get();

        $sortedGrades = self::groupPairedGrades(array_values($grades->all()));

        /** @var list<array{mark: ?GradeMark, units: float}> $gpaRows */
        $gpaRows = array_map(fn (AcademicGrade $grade): array => [
            'mark' => $grade->mark,
            'units' => (float) $grade->subject->units,
            'counts_toward_gpa' => SubjectGwaExclusionRule::countsTowardGwa($grade->subject->code),
        ], $sortedGrades);

        $excludedFromGpaCount = count(array_filter(
            $sortedGrades,
            fn (AcademicGrade $grade): bool => ! ($grade->mark?->countsTowardGpa() ?? false)
                || ! SubjectGwaExclusionRule::countsTowardGwa($grade->subject->code),
        ));

        return new GradeSlip(
            student: $student,
            term: $term,
            grades: $sortedGrades,
            totalAcademicUnits: GradePointAverage::academicUnits($gpaRows),
            gpaUnits: GradePointAverage::gpaUnits($gpaRows),
            gpa: GradePointAverage::compute($gpaRows),
            excludedFromGpaCount: $excludedFromGpaCount,
        );
    }

    /**
     * Orders grades so that a Lecture subject and its paired Laboratory
     * subject always appear adjacent, with Lecture first (Stakeholder Doc 18).
     *
     * @param  list<AcademicGrade>  $grades
     * @return list<AcademicGrade>
     */
    private static function groupPairedGrades(array $grades): array
    {
        /** @var array<int, AcademicGrade> $bySubjectId */
        $bySubjectId = [];
        foreach ($grades as $grade) {
            $bySubjectId[$grade->subject_id] = $grade;
        }

        $placed = [];
        $grouped = [];

        foreach ($grades as $grade) {
            $subjectId = $grade->subject_id;
            if (isset($placed[$subjectId])) {
                continue;
            }

            $pairedId = $grade->subject->paired_subject_id;
            $partner = $pairedId !== null ? ($bySubjectId[$pairedId] ?? null) : null;

            if ($partner !== null && ! isset($placed[$partner->subject_id])) {
                $isLab = ! $grade->subject->isLectureComponent();

                if ($isLab) {
                    $grouped[] = $partner;
                    $placed[$partner->subject_id] = true;
                    $grouped[] = $grade;
                    $placed[$subjectId] = true;
                } else {
                    $grouped[] = $grade;
                    $placed[$subjectId] = true;
                    $grouped[] = $partner;
                    $placed[$partner->subject_id] = true;
                }

                continue;
            }

            $grouped[] = $grade;
            $placed[$subjectId] = true;
        }

        return $grouped;
    }
}
