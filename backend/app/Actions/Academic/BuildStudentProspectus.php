<?php

namespace App\Actions\Academic;

use App\Domain\Academic\CompletionOnlySubjectRule;
use App\Domain\Academic\Prospectus;
use App\Domain\Academic\ProspectusEntry;
use App\Domain\Academic\ProspectusSemester;
use App\Domain\Curriculum\SemesterCoverage;
use App\Domain\Curriculum\SemesterSlot;
use App\Models\AcademicGrade;
use App\Models\CurriculumSubject;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * Builds a student's full curriculum prospectus (year 1 sem 1 through
 * year 4 sem 2) in a small, fixed number of queries: every placement in the
 * student's curriculum (plus its eager-loaded subject and prerequisites),
 * and every grade the student has ever recorded. Everything else —
 * grouping, the earliest-vs-actual-term semester bucket, the
 * unplaced-entries split — is in-memory.
 */
final readonly class BuildStudentProspectus
{
    public function execute(StudentProfile $student): Prospectus
    {
        $placements = CurriculumSubject::query()
            ->where('curriculum_id', $student->curriculum_id)
            ->with(['subject', 'prerequisites.prerequisiteSubject'])
            ->orderBy('year_level')
            ->orderBy('semester')
            ->get();

        $grades = AcademicGrade::query()
            ->where('student_id', $student->id)
            ->with(['subject', 'academicTerm'])
            ->orderByDesc('academic_term_id')
            ->orderByDesc('id')
            ->get();

        $gradesBySubject = $grades->groupBy('subject_id');
        $placedSubjectIds = $placements->pluck('subject_id')->unique();

        /** @var list<string> $prefixes */
        $prefixes = (array) config('enrollment.grading.completion_only_code_prefixes', []);

        /** @var array<int, array<string, list<ProspectusEntry>>> $entriesBySlot */
        $entriesBySlot = [];

        foreach ($placements as $placement) {
            /** @var Collection<int, AcademicGrade> $attempts */
            $attempts = $gradesBySubject->get($placement->subject_id) ?? collect();

            $entry = new ProspectusEntry(
                placement: $placement,
                isCompletionOnly: CompletionOnlySubjectRule::matches($placement->subject->code, $prefixes),
                attempts: array_values($attempts->all()),
            );

            $slot = $this->resolveSlot($placement, $entry->attempts);
            $entriesBySlot[$placement->year_level][$slot->value][] = $entry;
        }

        $semesters = [];

        for ($year = 1; $year <= 4; $year++) {
            foreach ([SemesterSlot::First, SemesterSlot::Second] as $slot) {
                $entries = $entriesBySlot[$year][$slot->value] ?? [];

                if ($entries === []) {
                    continue;
                }

                $semesters[] = new ProspectusSemester($year, $slot, self::groupPairedEntries($entries));
            }
        }

        $unplaced = array_values($grades->whereNotIn('subject_id', $placedSubjectIds)->all());

        return new Prospectus($student, $semesters, $unplaced);
    }

    /**
     * Orders entries so that a Lecture subject and its paired Laboratory
     * subject always appear adjacent, with Lecture first (Stakeholder Doc 18).
     *
     * @param  list<ProspectusEntry>  $entries
     * @return list<ProspectusEntry>
     */
    private static function groupPairedEntries(array $entries): array
    {
        /** @var array<int, ProspectusEntry> $bySubjectId */
        $bySubjectId = [];
        foreach ($entries as $entry) {
            $bySubjectId[$entry->placement->subject_id] = $entry;
        }

        $placed = [];
        $grouped = [];

        foreach ($entries as $entry) {
            $subjectId = $entry->placement->subject_id;
            if (isset($placed[$subjectId])) {
                continue;
            }

            $pairedId = $entry->placement->subject->paired_subject_id;
            $partner = $pairedId !== null ? ($bySubjectId[$pairedId] ?? null) : null;

            if ($partner !== null && ! isset($placed[$partner->placement->subject_id])) {
                $isLab = ! $entry->placement->subject->isLectureComponent();

                if ($isLab) {
                    $grouped[] = $partner;
                    $placed[$partner->placement->subject_id] = true;
                    $grouped[] = $entry;
                    $placed[$subjectId] = true;
                } else {
                    $grouped[] = $entry;
                    $placed[$subjectId] = true;
                    $grouped[] = $partner;
                    $placed[$partner->placement->subject_id] = true;
                }

                continue;
            }

            $grouped[] = $entry;
            $placed[$subjectId] = true;
        }

        return $grouped;
    }

    /**
     * The semester bucket a placement's row is rendered under: for flexible
     * placements that cover both semesters ('1st|2nd'), the actual term of
     * the student's most recent grade attempt, when one exists, overrides the
     * curriculum's default primary slot — real history beats the plan.
     * Fixed-semester placements ('1st' or '2nd') always remain anchored to
     * their curriculum slot so the official curriculum structure is preserved.
     *
     * @param  list<AcademicGrade>  $attempts  Newest-first.
     */
    private function resolveSlot(CurriculumSubject $placement, array $attempts): SemesterSlot
    {
        if (SemesterCoverage::coversBoth($placement->semester)) {
            $latest = $attempts[0] ?? null;
            $term = $latest?->academicTerm;

            if ($term !== null) {
                $slot = SemesterSlot::tryFrom($term->semester);

                if ($slot !== null) {
                    return $slot;
                }
            }
        }

        return SemesterCoverage::primary($placement->semester);
    }
}
