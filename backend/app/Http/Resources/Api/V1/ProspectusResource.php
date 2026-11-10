<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\Prospectus;
use App\Domain\Academic\ProspectusEntry;
use App\Domain\Academic\ProspectusSemester;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Models\AcademicGrade;
use App\Models\CurriculumMigration;
use App\Models\CurriculumMigrationCredit;
use App\Models\CurriculumSubjectEquivalency;
use App\Models\SubjectPrerequisite;
use App\Models\TransfereeCredit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Prospectus $resource
 */
final class ProspectusResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     *
     * @return array{
     *     type: string,
     *     student_id: int,
     *     student_number: string,
     *     program_code: string,
     *     program_name: string,
     *     curriculum_id: int,
     *     curriculum_name: string,
     *     effective_school_year: string,
     *     year_level: int,
     *     enrollment_category: ?string,
     *     enrollment_category_label: ?string,
     *     enrollment_category_derived_at: ?string,
     *     curriculum_transition: ?array<string, mixed>,
     *     transferee_credits: list<array<string, mixed>>,
     *     semesters: list<mixed>,
     *     unplaced_entries: list<mixed>
     * }
     */
    public function toArray(Request $request): array
    {
        $student = $this->resource->student;
        $curriculum = $student->curriculum;
        $migration = CurriculumMigration::query()
            ->where('student_id', $student->id)
            ->where('target_curriculum_id', $curriculum->id)
            ->with(['sourceCurriculum', 'targetCurriculum', 'credits.equivalency.sourceSubject', 'credits.equivalency.targetSubject'])
            ->latest('migrated_at')
            ->first();

        // Subjects credited from another school and mapped to a GRC subject: the ones the Registrar
        // Head approved, and the ones the Program Chair passed on that the Head has yet to decide
        // (owner, 2026-10-15), marked as such. A credit still with the Chair, or turned down, is not
        // shown. Listed beside the curriculum transition: like it, they carry no GRC grade (ADR 0026).
        $transfereeCredits = TransfereeCredit::query()
            ->where('student_id', $student->id)
            ->whereIn('status', [TransfereeCreditStatus::Approved->value, TransfereeCreditStatus::Endorsed->value])
            ->whereNotNull('subject_id')
            ->with('subject')
            ->orderBy('id')
            ->get();

        // One credit per subject for the curriculum rows; an approved one wins over an endorsed one.
        /** @var array<int, TransfereeCredit> $creditBySubject */
        $creditBySubject = [];
        foreach ($transfereeCredits as $credit) {
            $existing = $creditBySubject[$credit->subject_id] ?? null;
            if ($existing === null || ($existing->status !== TransfereeCreditStatus::Approved && $credit->status === TransfereeCreditStatus::Approved)) {
                $creditBySubject[(int) $credit->subject_id] = $credit;
            }
        }

        return [
            'type' => 'prospectus',
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'program_code' => $student->program->code,
            'program_name' => $student->program->name,
            'curriculum_id' => $curriculum->id,
            'curriculum_name' => $curriculum->name,
            'effective_school_year' => $curriculum->effective_school_year,
            'year_level' => $student->year_level,
            'enrollment_category' => $student->enrollment_category,
            'enrollment_category_label' => $student->enrollment_category !== null
                ? ucfirst($student->enrollment_category)
                : null,
            'enrollment_category_derived_at' => $student->enrollment_category_derived_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'curriculum_transition' => $migration === null ? null : [
                'source_curriculum_name' => $migration->sourceCurriculum->name,
                'target_curriculum_name' => $migration->targetCurriculum->name,
                'migrated_at' => $migration->migrated_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'credits' => $migration->credits
                    ->map(static function (CurriculumMigrationCredit $credit): ?array {
                        $equivalency = $credit->equivalency;
                        if (! $equivalency instanceof CurriculumSubjectEquivalency) {
                            return null;
                        }
                        $sourceSubject = $equivalency->sourceSubject;
                        $targetSubject = $equivalency->targetSubject;

                        return [
                            'source_code' => $sourceSubject->code,
                            'source_title' => $sourceSubject->title,
                            'target_code' => $targetSubject->code,
                            'target_title' => $targetSubject->title,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all(),
            ],
            'transferee_credits' => array_values($transfereeCredits
                ->map(static fn (TransfereeCredit $credit): array => [
                    'source_institution' => $credit->source_institution,
                    'source_subject_code' => $credit->source_subject_code,
                    'source_subject_title' => $credit->source_subject_title,
                    'source_grade' => $credit->source_grade,
                    'credited_units' => $credit->credited_units,
                    'source_school_year' => $credit->source_school_year,
                    'source_semester' => $credit->source_semester,
                    'target_code' => $credit->subject?->code,
                    'target_title' => $credit->subject?->title,
                    ...self::creditStatus($credit),
                ])
                ->all()),
            'semesters' => array_map(
                fn (ProspectusSemester $semester): array => $this->semesterToArray($semester, $creditBySubject),
                $this->resource->semesters,
            ),
            'unplaced_entries' => array_map(
                fn (AcademicGrade $grade): array => $this->unplacedToArray($grade),
                $this->resource->unplacedEntries,
            ),
        ];
    }

    /**
     * What the Prospectus says about the standing of a credit: approved by the Registrar Head, or
     * passed on by the Program Chair and still waiting for the Head.
     *
     * @return array{status: string, status_label: string, awaiting_registrar_head_approval: bool}
     */
    private static function creditStatus(TransfereeCredit $credit): array
    {
        $awaiting = $credit->status === TransfereeCreditStatus::Endorsed;

        return [
            'status' => $credit->status->value,
            'status_label' => $awaiting ? 'Awaiting Registrar Head approval' : 'Credited',
            'awaiting_registrar_head_approval' => $awaiting,
        ];
    }

    /**
     * @param  array<int, TransfereeCredit>  $creditBySubject
     * @return array{year_level: int, semester: string, semester_label: string, entries: list<mixed>}
     */
    private function semesterToArray(ProspectusSemester $semester, array $creditBySubject): array
    {
        return [
            'year_level' => $semester->yearLevel,
            'semester' => $semester->semester->value,
            'semester_label' => $semester->semester->label(),
            'entries' => array_map(
                fn (ProspectusEntry $entry): array => $this->entryToArray($entry, $creditBySubject),
                $semester->entries,
            ),
        ];
    }

    /**
     * @param  array<int, TransfereeCredit>  $creditBySubject
     * @return array{
     *     subject_id: int,
     *     code: string,
     *     title: string,
     *     units: float,
     *     is_required: bool,
     *     offered_either_semester: bool,
     *     is_completion_only: bool,
     *     mark: ?string,
     *     mark_label: ?string,
     *     final_grade: ?string,
     *     status: ?string,
     *     status_label: ?string,
     *     academic_term_id: ?int,
     *     term_label: ?string,
     *     attempt_count: int,
     *     credit: ?array<string, mixed>,
     *     prerequisites: list<array{subject_id: int, code: string, title: string, minimum_grade: string}>
     * }
     */
    private function entryToArray(ProspectusEntry $entry, array $creditBySubject): array
    {
        $subject = $entry->placement->subject;
        $grade = $entry->latestGrade();
        $term = $grade?->academicTerm;

        return [
            'subject_id' => $subject->id,
            'code' => $subject->code,
            'title' => $subject->title,
            'units' => $subject->units,
            'is_required' => $entry->placement->is_required,
            'offered_either_semester' => $entry->offeredEitherSemester(),
            'is_completion_only' => $entry->isCompletionOnly,
            'mark' => $grade?->mark?->value,
            'mark_label' => $grade?->mark?->label(),
            'final_grade' => $grade?->final_grade,
            'status' => $grade?->status->value,
            'status_label' => $grade?->mark === GradeMark::Withdrawn ? 'Withdrawn' : $grade?->status->label(),
            'academic_term_id' => $grade?->academic_term_id,
            'term_label' => $term !== null ? "{$term->school_year} · {$term->semester}" : null,
            'attempt_count' => count($entry->attempts),
            // The previous school's grade for a subject credited to this one, with whether the
            // Registrar Head has approved it yet; null when nothing is credited to the subject.
            'credit' => isset($creditBySubject[$subject->id])
                ? [
                    'source_institution' => $creditBySubject[$subject->id]->source_institution,
                    'source_subject_title' => $creditBySubject[$subject->id]->source_subject_title,
                    'source_grade' => $creditBySubject[$subject->id]->source_grade,
                    ...self::creditStatus($creditBySubject[$subject->id]),
                ]
                : null,
            'prerequisites' => array_values($entry->placement->prerequisites
                ->map(fn (SubjectPrerequisite $prerequisite): array => [
                    'subject_id' => $prerequisite->prerequisiteSubject->id,
                    'code' => $prerequisite->prerequisiteSubject->code,
                    'title' => $prerequisite->prerequisiteSubject->title,
                    'minimum_grade' => $prerequisite->minimum_grade,
                ])
                ->all()),
        ];
    }

    /**
     * @return array{
     *     subject_id: int,
     *     code: string,
     *     title: string,
     *     units: float,
     *     mark: ?string,
     *     mark_label: ?string,
     *     final_grade: ?string,
     *     status: string,
     *     status_label: string,
     *     academic_term_id: int,
     *     term_label: string
     * }
     */
    private function unplacedToArray(AcademicGrade $grade): array
    {
        // academic_term_id is a required (NOT NULL) column, so the
        // relation is always present here -- unlike entryToArray()'s
        // $grade itself, which can be null.
        $term = $grade->academicTerm;

        return [
            'subject_id' => $grade->subject->id,
            'code' => $grade->subject->code,
            'title' => $grade->subject->title,
            'units' => $grade->subject->units,
            'mark' => $grade->mark?->value,
            'mark_label' => $grade->mark?->label(),
            'final_grade' => $grade->final_grade,
            'status' => $grade->status->value,
            'status_label' => $grade->mark === GradeMark::Withdrawn ? 'Withdrawn' : $grade->status->label(),
            'academic_term_id' => $grade->academic_term_id,
            'term_label' => "{$term->school_year} · {$term->semester}",
        ];
    }
}
