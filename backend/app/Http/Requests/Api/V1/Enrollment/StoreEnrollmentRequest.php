<?php

namespace App\Http\Requests\Api\V1\Enrollment;

use App\Actions\Academic\EvaluateCreditMappingStatus;
use App\Actions\Enrollment\BuildEligibleSubjectPool;
use App\Actions\Enrollment\BuildEnrollmentBlockPool;
use App\Domain\Enrollment\EnrollmentAudience;
use App\Domain\Enrollment\EnrollmentBlock;
use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentWindowResolver;
use App\Domain\Enrollment\OverloadEvaluator;
use App\Domain\Enrollment\OverloadVerdict;
use App\Domain\Scheduling\SectionConflictDetector;
use App\Models\AcademicTerm;
use App\Models\AcademicTermEnrollmentWindow;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The client's eligible-subject pool view is advisory only — every submitted
 * section is re-validated here against a freshly built pool (FR-ENR-002,
 * FR-ENR-003, FR-ENR-011), the same authoritative-server pattern already
 * used throughout this codebase (e.g. Faculty section scoping, Executive
 * Director published-only visibility in Phase 5).
 */
final class StoreEnrollmentRequest extends FormRequest
{
    /**
     * Populated by `withValidator()` when the submission is `block_code`:
     * the section ids the client's block code expanded to server-side, so
     * `EnrollmentController` never trusts a client-supplied section list
     * for a block submission.
     *
     * @var list<int>
     */
    private array $resolvedBlockSectionIds = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'sections' => ['required_without:block_code', 'prohibits:block_code', 'array', 'min:1'],
            'sections.*.section_id' => ['required', 'integer', 'distinct', 'exists:sections,id'],
            'block_code' => ['required_without:sections', 'prohibits:sections', 'string', 'max:32'],
            // The student's "yes, I am sure" for a load over the unit maximum (see `rejectOverload`).
            'units_confirmed' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $student = $this->resolveStudent();
            $term = $this->resolveTerm();

            if ($student === null || $term === null) {
                return;
            }

            // First check, ahead of every other rule below: a closed window
            // should read as one clear message, not a wall of per-section
            // errors that all stem from the same root cause. This is also
            // what actually rejects submission against a `draft` or
            // `archived` term id — previously nothing did.
            if (! $this->rejectClosedEnrollmentWindow($validator, $student, $term)) {
                return;
            }

            if ($this->hasActiveEnrollmentThisTerm($student->id, $term->id)) {
                $validator->errors()->add('academic_term_id', 'You already have an active enrollment for this term.');

                return;
            }

            $creditMappingStatus = (new EvaluateCreditMappingStatus)->execute($student);
            if (! $creditMappingStatus->isCompleted) {
                $validator->errors()->add(
                    'academic_term_id',
                    $creditMappingStatus->reason ?? 'Credit mapping must be completed and approved before enrolling.'
                );

                return;
            }

            $blockCode = $this->input('block_code');
            if (is_string($blockCode) && $blockCode !== '') {
                $this->validateBlockSubmission($validator, $student, $term, $blockCode);

                return;
            }

            $sectionIds = $this->submittedSectionIds();
            if ($sectionIds === []) {
                return;
            }

            $sections = Section::query()->whereIn('id', $sectionIds)->with('subject')->get()->keyBy('id');

            $this->rejectDuplicateSubjects($validator, $sectionIds, $sections);
            $this->rejectScheduleConflicts($validator, $sectionIds, $sections);
            $this->rejectIneligibleSections($validator, $student, $term, $sectionIds);
            $this->rejectUnpairedLectureLabComponents($validator, $sectionIds, $sections);
            $this->rejectOverload($validator, $sections, $student);
        });
    }

    /**
     * @return list<int>
     */
    public function resolvedSectionIds(): array
    {
        $blockCode = $this->input('block_code');

        return is_string($blockCode) && $blockCode !== ''
            ? $this->resolvedBlockSectionIds
            : $this->submittedSectionIds();
    }

    /**
     * A block submission skips `rejectIneligibleSections()` entirely — that
     * check runs the per-subject eligible pool, a different rule than the
     * one that actually governs a block. `BuildEnrollmentBlockPool` is the
     * authoritative check here instead, via `is_selectable`: it already
     * withholds a block once any subject in it is already passed, since a
     * repeater no longer advances in lockstep with a single block and needs
     * the Registrar to reclassify them as irregular first. Its fixed subject
     * list is server-resolved as one Program Chair-authored choice, so it is
     * not rechecked as though the student had assembled those individual
     * sections: schedule validation belongs to generation and publication.
     * Overload checks still apply here.
     * Regular block submissions are prescribed curriculum loads and are
     * exempt from individual overload rejection.
     */
    private function validateBlockSubmission(Validator $validator, StudentProfile $student, AcademicTerm $term, string $blockCode): void
    {
        $block = collect(app(BuildEnrollmentBlockPool::class)->execute($student, $term))
            ->first(fn (EnrollmentBlock $candidate): bool => $candidate->blockCode === $blockCode);

        if ($block === null) {
            $validator->errors()->add('block_code', 'This section is not available for your year level and curriculum.');

            return;
        }

        if (! $block->isSelectable) {
            $validator->errors()->add('block_code', $block->reasons[0]['message'] ?? 'This section is not currently selectable.');

            return;
        }

        $sectionIds = array_map(fn (Section $section): int => $section->id, $block->sections);
        $this->resolvedBlockSectionIds = $sectionIds;
    }

    private function resolveStudent(): ?StudentProfile
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return null;
        }

        return StudentProfile::query()->where('user_id', $user->id)->first();
    }

    private function resolveTerm(): ?AcademicTerm
    {
        $termId = $this->input('academic_term_id');

        if (! is_numeric($termId)) {
            return null;
        }

        return AcademicTerm::query()->find((int) $termId);
    }

    /**
     * @return list<int>
     */
    private function submittedSectionIds(): array
    {
        $sectionInputs = $this->input('sections');

        if (! is_array($sectionInputs)) {
            return [];
        }

        $sectionIds = [];

        foreach ($sectionInputs as $input) {
            if (is_array($input) && isset($input['section_id']) && is_numeric($input['section_id'])) {
                $sectionIds[] = (int) $input['section_id'];
            }
        }

        return $sectionIds;
    }

    /**
     * @return bool false when the window is closed (an error was already added)
     */
    private function rejectClosedEnrollmentWindow(Validator $validator, StudentProfile $student, AcademicTerm $term): bool
    {
        $audience = EnrollmentAudience::forStudent($student->enrollment_category, $student->year_level);
        $window = AcademicTermEnrollmentWindow::query()
            ->where('academic_term_id', $term->id)
            ->where('audience', $audience->value)
            ->first();

        $availability = EnrollmentWindowResolver::resolve(
            $term->status,
            $term->enrollment_opens_at,
            $term->enrollment_closes_at,
            $window?->opens_at,
            $window?->closes_at,
            CarbonImmutable::now(),
        );

        if (! $availability->isOpen) {
            $validator->errors()->add('academic_term_id', $availability->reason->message($audience));

            return false;
        }

        return true;
    }

    private function hasActiveEnrollmentThisTerm(int $studentId, int $academicTermId): bool
    {
        return Enrollment::query()
            ->where('student_id', $studentId)
            ->where('academic_term_id', $academicTermId)
            ->whereNotIn('status', EnrollmentStatus::terminalValues())
            ->exists();
    }

    /**
     * @param  list<int>  $sectionIds
     * @param  Collection<int, Section>  $sections
     */
    private function rejectDuplicateSubjects(Validator $validator, array $sectionIds, Collection $sections): void
    {
        $seenSubjectIds = [];

        foreach ($sectionIds as $index => $sectionId) {
            $section = $sections->get($sectionId);

            if ($section === null) {
                continue;
            }

            if (in_array($section->subject_id, $seenSubjectIds, true)) {
                $validator->errors()->add("sections.{$index}.section_id", 'Only one section per subject may be submitted.');
            }

            $seenSubjectIds[] = $section->subject_id;
        }
    }

    /**
     * @param  list<int>  $sectionIds
     * @param  Collection<int, Section>  $sections
     */
    private function rejectScheduleConflicts(Validator $validator, array $sectionIds, Collection $sections): void
    {
        $detector = app(SectionConflictDetector::class);

        foreach ($sectionIds as $index => $sectionId) {
            $section = $sections->get($sectionId);

            if ($section === null) {
                continue;
            }

            $others = array_values(
                $sections->except([$sectionId])->map(fn (Section $other): array => [
                    'schedule_days' => $other->schedule_days,
                    'starts_at_time' => $other->starts_at_time,
                    'ends_at_time' => $other->ends_at_time,
                ])->all(),
            );

            $proposed = [
                'schedule_days' => $section->schedule_days,
                'starts_at_time' => $section->starts_at_time,
                'ends_at_time' => $section->ends_at_time,
            ];

            if ($detector->hasConflict($proposed, $others)) {
                $validator->errors()->add("sections.{$index}.section_id", 'This section conflicts with another section in this submission.');
            }
        }
    }

    /**
     * @param  list<int>  $sectionIds
     */
    private function rejectIneligibleSections(Validator $validator, StudentProfile $student, AcademicTerm $term, array $sectionIds): void
    {
        $pool = app(BuildEligibleSubjectPool::class)->execute($student, $term);

        $eligibleSectionIds = [];
        foreach ($pool as $entry) {
            foreach ($entry->availableSections as $availableSection) {
                $eligibleSectionIds[$availableSection->id] = true;
            }
        }

        foreach ($sectionIds as $index => $sectionId) {
            if (! isset($eligibleSectionIds[$sectionId])) {
                $validator->errors()->add("sections.{$index}.section_id", 'This section is not currently eligible for selection.');
            }
        }
    }

    /**
     * A subject's lecture and laboratory components are institutional
     * policy always taken together, never separately (`Subject::$paired_subject_id`,
     * set once by a title/code-based backfill — see its migration). This is
     * the server-side enforcement boundary; `EligibleSubjectTable` already
     * keeps the two in sync automatically on the happy path, so this should
     * only ever fire against a client that bypassed that UX. Block-code
     * submissions are exempt — `validateBlockSubmission()`'s own docblock
     * already establishes that a Program Chair-authored block's fixed
     * subject list is trusted as a whole, not re-validated subject by
     * subject.
     *
     * @param  list<int>  $sectionIds
     * @param  Collection<int, Section>  $sections
     */
    private function rejectUnpairedLectureLabComponents(Validator $validator, array $sectionIds, Collection $sections): void
    {
        $sectionCodeBySubjectId = [];
        foreach ($sections as $section) {
            $sectionCodeBySubjectId[$section->subject_id] = $section->section_code;
        }

        foreach ($sectionIds as $index => $sectionId) {
            $section = $sections->get($sectionId);
            $pairedSubjectId = $section?->subject->paired_subject_id;

            if ($pairedSubjectId === null) {
                continue;
            }

            if (! array_key_exists($pairedSubjectId, $sectionCodeBySubjectId)) {
                $validator->errors()->add(
                    "sections.{$index}.section_id",
                    'This subject\'s lecture and laboratory components must be taken together.',
                );

                continue;
            }

            if ($sectionCodeBySubjectId[$pairedSubjectId] !== $section->section_code) {
                $validator->errors()->add(
                    "sections.{$index}.section_id",
                    'The lecture and laboratory sections must be the same section.',
                );
            }
        }
    }

    /**
     * Only the `Rejected` verdict blocks submission here — `RequiresApproval`
     * is not an error; `SubmitEnrollment` records that flag on the
     * enrollment itself, and `UpdateEnrollmentRequest` gates
     * `registrar_approve` on it being acknowledged. See
     * `App\Domain\Enrollment\OverloadEvaluator`.
     *
     * @param  Collection<int, Section>  $sections
     */
    private function rejectOverload(Validator $validator, Collection $sections, ?StudentProfile $student = null): void
    {
        $totalUnits = (float) $sections->sum(fn (Section $section): float => $section->subject->units);
        $maxRegularUnits = $student?->curriculum?->effectiveRegularUnits()
            ?? $this->numericConfigValue('enrollment.max_regular_units');
        $overloadMaxUnits = $student?->curriculum?->effectiveMaxUnits()
            ?? $this->numericConfigValue('enrollment.overload_max_units');

        $verdict = OverloadEvaluator::evaluate($totalUnits, $maxRegularUnits, $overloadMaxUnits);

        if ($verdict !== OverloadVerdict::Rejected) {
            return;
        }

        $cap = $overloadMaxUnits ?? $maxRegularUnits;

        // A Regular student's load is their curriculum's, so it still cannot pass the maximum. An irregular student
        // may: they are asked whether they are sure (`units_confirmed`), and the Program Chair then sees the load is
        // over and sets the right units and schedule (`SubmitEnrollment` sends it to them).
        if ($student?->enrollment_category === EnrollmentCategory::Regular->value) {
            $validator->errors()->add(
                'sections',
                sprintf('This selection totals %s units, exceeding the maximum allowed load of %s units.', $totalUnits, $cap),
            );

            return;
        }

        if (! $this->boolean('units_confirmed')) {
            $validator->errors()->add(
                'units_confirmed',
                sprintf('This selection totals %s units, over the %s unit maximum. Confirm that you are sure about these units to submit it for your Program Head to review.', $totalUnits, $cap),
            );
        }
    }

    private function numericConfigValue(string $key): ?float
    {
        $raw = config($key);

        return is_numeric($raw) ? (float) $raw : null;
    }
}
