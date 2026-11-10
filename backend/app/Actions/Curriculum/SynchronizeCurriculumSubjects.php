<?php

namespace App\Actions\Curriculum;

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\SubjectPrerequisite;

/**
 * Replaces a curriculum's entire subject-placement and prerequisite graph
 * with the submitted one. The cycle check (StoreCurriculumRequest /
 * UpdateCurriculumRequest) already ran against this exact payload before
 * this class is invoked, so no further validation happens here.
 */
final class SynchronizeCurriculumSubjects
{
    /**
     * The `reference_*` schedule/faculty columns on `curriculum_subjects`
     * hold real data transcribed from GRC's official Excel schedules and
     * seeded once by `GrcCurriculumScheduleReferenceSeeder`. They are not
     * part of the editor's payload, so the delete-and-recreate below would
     * silently drop them on every save unless they are carried forward.
     *
     * @var list<string>
     */
    private const REFERENCE_COLUMNS = [
        'reference_day',
        'reference_start_time',
        'reference_end_time',
        'reference_room',
        'reference_modality',
        'reference_professor_name',
        'reference_sched_id',
        'reference_notes',
    ];

    /**
     * @param  list<array{subject_id: int, year_level: int, semester: string, is_required: bool, prerequisites: list<array{prerequisite_subject_id: int, minimum_grade: string}>}>  $subjects
     */
    public function execute(Curriculum $curriculum, array $subjects): void
    {
        // Snapshot the reference data before the rows go away, keyed by the
        // subject it describes — that is the only stable identity across the
        // replace, since every placement row gets a brand-new primary key.
        // A subject_id that was not previously placed simply has no entry
        // here and stays null, exactly as before: nothing is ever invented.
        $referencesBySubjectId = $curriculum->subjectPlacements()
            ->get(array_merge(['subject_id'], self::REFERENCE_COLUMNS))
            ->keyBy('subject_id')
            ->map(fn (CurriculumSubject $placement): array => $placement->only(self::REFERENCE_COLUMNS))
            ->all();

        // Equivalencies are keyed by the stable subject IDs rather than this
        // table's transient placement primary keys. Keep mappings for rows
        // the editor re-submits, but discard one as soon as its target is
        // truly removed from the Draft curriculum.
        $submittedSubjectIds = array_map(
            static fn (array $subject): int => $subject['subject_id'],
            $subjects,
        );
        $equivalencies = $curriculum->targetEquivalencies();
        if ($submittedSubjectIds === []) {
            $equivalencies->delete();
        } else {
            $equivalencies->whereNotIn('target_subject_id', $submittedSubjectIds)->delete();
        }

        // The transaction-owning curriculum use case calls this method.
        // Cascading the placement deletion also removes its prerequisites.
        $curriculum->subjectPlacements()->delete();

        $placementIdBySubjectId = [];

        foreach ($subjects as $subject) {
            $placement = $curriculum->subjectPlacements()->create([
                'subject_id' => $subject['subject_id'],
                'year_level' => $subject['year_level'],
                'semester' => $subject['semester'],
                'is_required' => $subject['is_required'],
            ] + ($referencesBySubjectId[$subject['subject_id']] ?? []));

            $placementIdBySubjectId[$subject['subject_id']] = $placement->id;
        }

        foreach ($subjects as $subject) {
            $placementId = $placementIdBySubjectId[$subject['subject_id']];

            foreach ($subject['prerequisites'] as $prerequisite) {
                SubjectPrerequisite::create([
                    'curriculum_subject_id' => $placementId,
                    'prerequisite_subject_id' => $prerequisite['prerequisite_subject_id'],
                    'minimum_grade' => $prerequisite['minimum_grade'],
                ]);
            }
        }
    }
}
