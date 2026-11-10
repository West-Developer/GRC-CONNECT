<?php

namespace App\Actions\Academic;

use App\Domain\Academic\AcademicRecord;
use App\Domain\Academic\AcademicRecordTerm;
use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradePointAverage;
use App\Domain\Academic\SubjectGwaExclusionRule;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Builds a student's full grade history in a single query — every term
 * they have a grade in, grouped and totalled the same way `BuildGradeSlip`
 * totals one term, latest term first. Never calls `BuildGradeSlip` in a
 * loop: that would be one query per term instead of one query total.
 */
final readonly class BuildAcademicRecord
{
    public function execute(StudentProfile $student): AcademicRecord
    {
        $grades = AcademicGrade::query()
            ->where('student_id', $student->id)
            ->with(['subject', 'section.professor', 'academicTerm'])
            ->orderBy('id')
            ->get();

        $terms = array_values(
            $grades
                ->groupBy('academic_term_id')
                ->map(fn (Collection $termGrades): AcademicRecordTerm => $this->buildTerm($termGrades))
                ->sort(fn (AcademicRecordTerm $a, AcademicRecordTerm $b): int => self::compareTermsLatestFirst($a->term, $b->term))
                ->all(),
        );

        return new AcademicRecord(
            student: $student,
            terms: $terms,
        );
    }

    /**
     * @param  Collection<int, AcademicGrade>  $termGrades
     */
    private function buildTerm(Collection $termGrades): AcademicRecordTerm
    {
        $firstGrade = $termGrades->first();

        if ($firstGrade === null) {
            // Unreachable: every group here comes from Collection::groupBy()
            // on a non-empty source, so no group is ever empty.
            throw new LogicException('Encountered an empty grade group while building an academic record.');
        }

        /** @var AcademicTerm $term */
        $term = $firstGrade->academicTerm;

        /** @var list<array{mark: ?GradeMark, units: float}> $gpaRows */
        $gpaRows = $termGrades
            ->map(fn (AcademicGrade $grade): array => [
                'mark' => $grade->mark,
                'units' => (float) $grade->subject->units,
                'counts_toward_gpa' => SubjectGwaExclusionRule::countsTowardGwa($grade->subject->code),
            ])
            ->all();

        $excludedFromGpaCount = $termGrades
            ->filter(fn (AcademicGrade $grade): bool => ! ($grade->mark?->countsTowardGpa() ?? false)
                || ! SubjectGwaExclusionRule::countsTowardGwa($grade->subject->code))
            ->count();

        return new AcademicRecordTerm(
            term: $term,
            grades: array_values($termGrades->all()),
            totalAcademicUnits: GradePointAverage::academicUnits($gpaRows),
            gpaUnits: GradePointAverage::gpaUnits($gpaRows),
            gpa: GradePointAverage::compute($gpaRows),
            excludedFromGpaCount: $excludedFromGpaCount,
        );
    }

    private static function compareTermsLatestFirst(AcademicTerm $a, AcademicTerm $b): int
    {
        $schoolYearComparison = $b->school_year <=> $a->school_year;

        if ($schoolYearComparison !== 0) {
            return $schoolYearComparison;
        }

        return self::semesterOrdinal($b->semester) <=> self::semesterOrdinal($a->semester);
    }

    private static function semesterOrdinal(string $semester): int
    {
        return $semester === '2nd' ? 2 : 1;
    }
}
