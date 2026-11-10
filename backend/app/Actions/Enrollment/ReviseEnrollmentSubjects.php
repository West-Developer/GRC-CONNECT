<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\OverloadEvaluator;
use App\Domain\Enrollment\OverloadVerdict;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Domain\Scheduling\SectionConflictDetector;
use App\Models\Enrollment;
use App\Models\EnrollmentRevision;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use App\Support\Notifications\ProgramChairRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lets a Program Head add or remove whole subjects from a student's
 * enrollment while it sits at their own review stage (owner-requested
 * feature, 2026-09-28: an overload or irregular student's submission may
 * need a subject swapped for one the student can actually take, decided by
 * the Program Head reviewing the prospectus — see `ProspectusDocument` in
 * the same review dialog).
 *
 * A full replacement list, not an add/remove delta: the caller sends the
 * complete set of section ids the enrollment should end up with, the same
 * shape `SubmitEnrollment` already validates from the student's own
 * submission. This is simpler to reason about than a delta and impossible to
 * apply out of order.
 *
 * Only legal while `pending_program_head_approval` — once the Registrar has
 * even seen it, this stage is over (mirrors `TransitionEnrollment`'s own
 * required-status guard). Seats are re-checked and locked exactly like
 * `SubmitEnrollment`; a subject dropped from the set releases its seat the
 * same way `TransitionEnrollment::releaseSeats` does for a terminal
 * transition. The hard overload ceiling still applies: a Program Head can
 * expand a student's load, but not past the curriculum's own maximum — the
 * same rule `SubmitEnrollment` enforces on the student's own submission.
 *
 * Changing a student's subjects is not a decision the student is bypassed on
 * (ADR 0040): saving a revision records the Program Chair's `$note` (why the
 * subjects were changed) and what was added and removed as an
 * `EnrollmentRevision`, and moves the enrollment to `pending_student_review`
 * so the student can accept (it goes straight to the Registrar) or decline with
 * a reason (it comes back to the Program Chair). Because the Chair does not
 * press Approve on this path, a load that needs overload approval is
 * acknowledged here (FR-ENR-004), the same acknowledgement Approve asks for.
 */
final readonly class ReviseEnrollmentSubjects
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
    ) {}

    /**
     * @param  list<int>  $sectionIds
     */
    public function execute(
        Enrollment $enrollment,
        array $sectionIds,
        User $actor,
        AuditRequestContext $context,
        string $note,
        bool $overloadAcknowledged = false,
    ): Enrollment {
        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => $actor->role === UserRole::Student
                    ? 'Explain why you are proposing these subjects.'
                    : 'Tell the student why you are changing their subjects.',
            ]);
        }

        // Only database side effects occur here, so a deadlock can safely retry
        // the entire transaction and re-check the now-current seat count.
        return DB::transaction(function () use ($enrollment, $sectionIds, $actor, $context, $note, $overloadAcknowledged): Enrollment {
            $lockedEnrollment = Enrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->with(['student.curriculum', 'student.user'])
                ->firstOrFail();

            $isStudentProposal = $actor->role === UserRole::Student;

            if ($isStudentProposal) {
                if ($lockedEnrollment->status !== EnrollmentStatus::PendingStudentReview) {
                    throw ValidationException::withMessages([
                        'section_ids' => "You can only propose subjects while your enrollment is 'pending_student_review'; it is currently '{$lockedEnrollment->status->value}'.",
                    ]);
                }
            } else {
                if ($lockedEnrollment->status !== EnrollmentStatus::PendingProgramHeadApproval) {
                    throw ValidationException::withMessages([
                        'section_ids' => "This enrollment must be 'pending_program_head_approval' to revise its subjects; it is currently '{$lockedEnrollment->status->value}'.",
                    ]);
                }
            }

            $beforeTotalUnits = (float) $lockedEnrollment->total_units;

            $existingSubjects = EnrollmentSubject::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->lockForUpdate()
                ->with('section.subject')
                ->get();
            // Only a currently seat-occupying row counts as "already there" —
            // a subject dropped by an earlier revision (or by the original
            // submission) still has a row (the table's unique
            // (enrollment_id, section_id) pair), so re-adding it must revive
            // that row rather than insert a second one.
            $activeExistingSectionIds = $existingSubjects
                ->filter(fn (EnrollmentSubject $s): bool => $s->status->occupiesSeat())
                ->pluck('section_id')
                ->all();

            $keptIds = array_values(array_intersect($activeExistingSectionIds, $sectionIds));
            $addedIds = array_values(array_diff($sectionIds, $activeExistingSectionIds));
            $removedIds = array_values(array_diff($activeExistingSectionIds, $sectionIds));

            if ($addedIds === [] && $removedIds === []) {
                throw ValidationException::withMessages([
                    'section_ids' => 'Add or remove at least one subject before sending changes to the student.',
                ]);
            }

            // Every section this revision touches, locked in one deterministic
            // order — the same lock-order discipline `SubmitEnrollment` follows,
            // so two concurrent revisions can never deadlock against each other.
            $touchedSectionIds = array_values(array_unique([...$addedIds, ...$removedIds]));
            $sections = Section::query()
                ->whereIn('id', $touchedSectionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->with('subject')
                ->get()
                ->keyBy('id');

            foreach ($addedIds as $sectionId) {
                $section = $sections->get($sectionId);
                if ($section === null) {
                    throw ValidationException::withMessages([
                        'section_ids' => "Section {$sectionId} does not exist.",
                    ]);
                }
                if ($section->academic_term_id !== $lockedEnrollment->academic_term_id) {
                    throw ValidationException::withMessages([
                        'section_ids' => "{$section->section_code} is not offered in this enrollment's term.",
                    ]);
                }
                if ($section->remainingSeats() < 1) {
                    throw ValidationException::withMessages([
                        'section_ids' => "{$section->section_code} ({$section->subject->code}) no longer has an open seat.",
                    ]);
                }
            }

            // Validate the complete proposed schedule before changing seats.
            $finalSections = Section::query()
                ->whereIn('id', [...$keptIds, ...$addedIds])
                ->with('subject')
                ->get();
            $subjectIds = [];
            // Match the database's course equivalence (code + units) used by
            // the eligible pool, including identical courses in other colleges.
            if (Subject::query()->select('code', 'units')->whereIn('id', $finalSections->pluck('subject_id'))
                ->groupBy('code', 'units')->havingRaw('COUNT(*) > 1')->exists()) {
                throw ValidationException::withMessages(['section_ids' => 'Only one section per equivalent subject may be submitted.']);
            }
            $slots = [];
            $detector = app(SectionConflictDetector::class);
            foreach ($finalSections as $section) {
                if (isset($subjectIds[$section->subject_id])) {
                    throw ValidationException::withMessages(['section_ids' => 'Only one section per subject may be submitted.']);
                }
                $subjectIds[$section->subject_id] = $section->section_code;
                $slot = [
                    'schedule_days' => $section->schedule_days,
                    'starts_at_time' => $section->starts_at_time,
                    'ends_at_time' => $section->ends_at_time,
                ];
                if ($detector->hasConflict($slot, $slots)) {
                    throw ValidationException::withMessages(['section_ids' => 'The proposed schedule contains conflicting sections.']);
                }
                $slots[] = $slot;
            }
            foreach ($finalSections as $section) {
                $pairedSubjectId = $section->subject->paired_subject_id;
                if ($pairedSubjectId !== null && (! array_key_exists($pairedSubjectId, $subjectIds) || $subjectIds[$pairedSubjectId] !== $section->section_code)) {
                    throw ValidationException::withMessages(['section_ids' => 'Lecture and laboratory components must be taken together in the same section.']);
                }
            }

            // Retained sections already occupy seats; validate only additions
            // against a pool that ignores this enrollment's own selections.
            if ($addedIds !== []) {
                $term = $lockedEnrollment->academicTerm;
                $eligibleIds = [];
                foreach (app(BuildEligibleSubjectPool::class)->execute($lockedEnrollment->student, $term, $lockedEnrollment->id) as $entry) {
                    foreach ($entry->availableSections as $availableSection) {
                        $eligibleIds[$availableSection->id] = true;
                    }
                }
                foreach ($addedIds as $sectionId) {
                    if (! isset($eligibleIds[$sectionId])) {
                        throw ValidationException::withMessages(['section_ids' => 'A selected section is not currently eligible for this student.']);
                    }
                }
            }

            $beforeSubjectCodes = $existingSubjects
                ->filter(fn (EnrollmentSubject $s): bool => $s->status->occupiesSeat())
                ->map(fn (EnrollmentSubject $s): string => $s->section->subject->code ?? (string) $s->section_id)
                ->all();

            $removedSnapshots = array_values(array_filter(array_map(
                function (int $sectionId) use ($existingSubjects): ?array {
                    $existing = $existingSubjects->first(fn (EnrollmentSubject $s): bool => $s->section_id === $sectionId);

                    return $existing === null ? null : self::subjectSnapshot($existing->section);
                },
                $removedIds,
            )));
            $addedSnapshots = array_values(array_filter(array_map(
                fn (int $sectionId): ?array => ($section = $sections->get($sectionId)) === null ? null : self::subjectSnapshot($section),
                $addedIds,
            )));

            foreach ($removedIds as $sectionId) {
                $subject = $existingSubjects->first(fn (EnrollmentSubject $s): bool => $s->section_id === $sectionId);
                if ($subject !== null && $subject->status->occupiesSeat()) {
                    $subject->update(['status' => EnrollmentSubjectStatus::Dropped]);
                    Section::query()->whereKey($sectionId)->where('enrolled_count', '>', 0)->decrement('enrolled_count');
                }
            }

            foreach ($addedIds as $sectionId) {
                // Revive a row dropped by an earlier revision/submission
                // rather than insert a second one — the unique
                // (enrollment_id, section_id) pair forbids a duplicate.
                $dropped = $existingSubjects->first(fn (EnrollmentSubject $s): bool => $s->section_id === $sectionId);
                if ($dropped !== null) {
                    $dropped->update(['status' => EnrollmentSubjectStatus::Selected]);
                } else {
                    EnrollmentSubject::create([
                        'enrollment_id' => $lockedEnrollment->id,
                        'section_id' => $sectionId,
                        'status' => EnrollmentSubjectStatus::Selected,
                    ]);
                }
                Section::query()->whereKey($sectionId)->increment('enrolled_count');
            }

            $totalUnits = (float) $finalSections->sum(fn (Section $section): float => $section->subject->units);

            $student = $lockedEnrollment->student;
            $maxRegularUnits = $student->curriculum->effectiveRegularUnits()
                ?? self::numericConfigValue('enrollment.max_regular_units');
            $overloadMaxUnits = $student->curriculum->effectiveMaxUnits()
                ?? self::numericConfigValue('enrollment.overload_max_units');
            $verdict = OverloadEvaluator::evaluate($totalUnits, $maxRegularUnits, $overloadMaxUnits);

            if ($verdict === OverloadVerdict::Rejected) {
                throw ValidationException::withMessages([
                    'section_ids' => "This revision totals {$totalUnits} units, exceeding the maximum allowed load.",
                ]);
            }

            // The Chair does not press Approve on this path, so the overload
            // acknowledgement Approve would ask for is asked for here.
            if (! $isStudentProposal && $verdict === OverloadVerdict::RequiresApproval && ! $overloadAcknowledged) {
                throw ValidationException::withMessages([
                    'overload_acknowledged' => 'This schedule exceeds the regular unit load and requires explicit overload acknowledgement before it is sent to the student.',
                ]);
            }

            $targetStatus = $isStudentProposal
                ? EnrollmentStatus::PendingProgramHeadApproval
                : EnrollmentStatus::PendingStudentReview;

            $lockedEnrollment->update([
                'total_units' => $totalUnits,
                'requires_overload_approval' => $verdict === OverloadVerdict::RequiresApproval,
                'status' => $targetStatus,
            ]);
            $lockedEnrollment->refresh();

            if ($isStudentProposal) {
                EnrollmentRevision::query()
                    ->where('enrollment_id', $lockedEnrollment->id)
                    ->where('status', EnrollmentRevision::STATUS_PENDING)
                    ->update([
                        'status' => EnrollmentRevision::STATUS_DECLINED,
                        'student_reason' => $note,
                        'responded_at' => now(),
                    ]);
            } else {
                EnrollmentRevision::query()
                    ->where('enrollment_id', $lockedEnrollment->id)
                    ->where('proposed_by', $student->user_id)
                    ->where('status', EnrollmentRevision::STATUS_PENDING)
                    ->update(['status' => 'superseded', 'responded_at' => now()]);
            }

            EnrollmentRevision::create([
                'enrollment_id' => $lockedEnrollment->id,
                'proposed_by' => $actor->id,
                'note' => $note,
                'added_subjects' => $addedSnapshots,
                'removed_subjects' => $removedSnapshots,
                'units_before' => $beforeTotalUnits,
                'units_after' => $totalUnits,
                'status' => EnrollmentRevision::STATUS_PENDING,
            ]);

            $afterSubjectCodes = Section::query()->whereIn('id', [...$keptIds, ...$addedIds])->with('subject')->get()
                ->map(fn (Section $s): string => $s->subject->code ?? (string) $s->id)->all();

            $auditAction = $isStudentProposal
                ? AuditAction::ENROLLMENT_STUDENT_DECLINED_REVISION
                : AuditAction::ENROLLMENT_PROGRAM_HEAD_SUBJECTS_REVISED;

            $this->auditRecorder->record(
                $actor,
                $auditAction,
                AuditableType::ENROLLMENT,
                $lockedEnrollment->id,
                ['subjects' => $beforeSubjectCodes, 'total_units' => $beforeTotalUnits],
                ['subjects' => $afterSubjectCodes, 'total_units' => $totalUnits, 'status' => $targetStatus->value],
                $note,
                $context,
            );

            if ($isStudentProposal) {
                $this->notificationRecorder->recordMany(
                    ProgramChairRecipients::forStudent($student),
                    NotificationType::EnrollmentRevisionDeclined,
                    sprintf('Student %s proposed a revised subject schedule for their enrollment. Note: %s', $student->student_number, $note),
                );
            } else {
                Notification::create([
                    'user_id' => $student->user_id,
                    'type' => NotificationType::EnrollmentProgramHeadSubjectsRevised,
                    'message' => "Your Program Chair changed the subjects in your enrollment. Reason: {$note} Open your Enrollment page to accept the changes or tell your Program Chair why you cannot.",
                ]);
            }

            return $lockedEnrollment->refresh()->load([
                'student', 'enrollmentSubjects.section.subject', 'enrollmentSubjects.section.professor', 'queueTicket', 'assessment.items', 'revisions',
            ]);
        }, attempts: 3);
    }

    /**
     * What a history entry keeps of a subject, so it still reads correctly if the section changes later.
     *
     * @return array{section_id: int, section_code: ?string, subject_code: string, subject_title: string, units: float}
     */
    private static function subjectSnapshot(Section $section): array
    {
        return [
            'section_id' => $section->id,
            'section_code' => $section->section_code,
            'subject_code' => $section->subject->code,
            'subject_title' => $section->subject->title,
            'units' => (float) $section->subject->units,
        ];
    }

    private static function numericConfigValue(string $key): ?float
    {
        $raw = config($key);

        return is_numeric($raw) ? (float) $raw : null;
    }
}
