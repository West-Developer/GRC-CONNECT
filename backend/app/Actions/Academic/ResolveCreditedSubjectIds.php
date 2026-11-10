<?php

namespace App\Actions\Academic;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Models\CurriculumMigrationCredit;
use App\Models\TransfereeCredit;

/**
 * Which subjects a student is already credited with, without a GRC grade
 * (ADR 0026). Two sources, kept separate so a caller can say where a credit
 * came from and merged when it only needs "is it credited?":
 *
 *   - `CurriculumMigrationCredit`: an old-curriculum subject the Program Chair
 *     carried over to the student's current curriculum.
 *   - An `approved` `TransfereeCredit` mapped to a subject: a subject taken at
 *     another school that the Program Chair endorsed and the Registrar Head
 *     approved. A credit that is only `pending`, `rejected`, or has no mapped
 *     subject credits nothing. An `endorsed` one (passed by the Chair, awaiting
 *     the Head) counts only for enrolment, as a provisional credit, when the
 *     caller asks for it (`$includeEndorsed`, owner 2026-10-15): promotion and
 *     the Chair's mapping suggestions keep to what the Head approved.
 *
 * A credited subject carries no grade, so PRD §17's cross-institution grade
 * equivalence stays open and no credit can ever feed a GWA.
 */
final readonly class ResolveCreditedSubjectIds
{
    /**
     * @param  list<int>  $studentIds
     * @return array<int, array<int, true>> student id => subject ids
     */
    public function fromCurriculumMigrations(array $studentIds, int $curriculumId): array
    {
        if ($studentIds === []) {
            return [];
        }

        $credits = CurriculumMigrationCredit::query()
            ->whereHas('migration', fn ($query) => $query
                ->whereIn('student_id', $studentIds)
                ->where('target_curriculum_id', $curriculumId))
            ->with('migration:id,student_id,target_curriculum_id')
            ->get(['id', 'curriculum_migration_id', 'target_subject_id']);

        $byStudent = [];
        foreach ($credits as $credit) {
            // whereHas('migration', ...) above already guarantees a match
            // exists at the DB level, but the eager-loaded relation is still
            // nullable to PHPStan's static analysis.
            if ($credit->migration === null) {
                continue;
            }

            $byStudent[$credit->migration->student_id][$credit->target_subject_id] = true;
        }

        return $byStudent;
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<int, array<int, true>> student id => subject ids
     */
    public function fromApprovedTransfereeCredits(array $studentIds): array
    {
        return $this->fromTransfereeCredits($studentIds, TransfereeCreditStatus::Approved);
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<int, array<int, true>> student id => subject ids
     */
    private function fromTransfereeCredits(array $studentIds, TransfereeCreditStatus $status): array
    {
        if ($studentIds === []) {
            return [];
        }

        $credits = TransfereeCredit::query()
            ->whereIn('student_id', $studentIds)
            ->where('status', $status->value)
            ->whereNotNull('subject_id')
            ->get(['student_id', 'subject_id']);

        $byStudent = [];
        foreach ($credits as $credit) {
            if ($credit->subject_id === null) {
                continue;
            }

            $byStudent[$credit->student_id][$credit->subject_id] = true;
        }

        return $byStudent;
    }

    /**
     * Subjects mapped by credits the Program Chair has passed to the Registrar Head and the Head has
     * not decided yet. Provisional: they count for a student's enrolment and are shown on the
     * Prospectus as awaiting the Head's approval.
     *
     * @param  list<int>  $studentIds
     * @return array<int, array<int, true>> student id => subject ids
     */
    public function fromEndorsedTransfereeCredits(array $studentIds): array
    {
        return $this->fromTransfereeCredits($studentIds, TransfereeCreditStatus::Endorsed);
    }

    /**
     * The sources merged: curriculum migrations and approved transferee credits, plus the endorsed
     * (provisional) ones when `$includeEndorsed`.
     *
     * @param  list<int>  $studentIds
     * @return array<int, array<int, true>> student id => subject ids
     */
    public function forStudents(array $studentIds, int $curriculumId, bool $includeEndorsed = false): array
    {
        $merged = $this->fromCurriculumMigrations($studentIds, $curriculumId);

        $sources = [$this->fromApprovedTransfereeCredits($studentIds)];
        if ($includeEndorsed) {
            $sources[] = $this->fromEndorsedTransfereeCredits($studentIds);
        }

        foreach ($sources as $source) {
            foreach ($source as $studentId => $subjectIds) {
                $merged[$studentId] = ($merged[$studentId] ?? []) + $subjectIds;
            }
        }

        return $merged;
    }
}
