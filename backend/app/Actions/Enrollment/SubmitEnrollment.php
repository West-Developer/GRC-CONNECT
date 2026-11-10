<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Enrollment\EnrollmentRouting;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\OverloadEvaluator;
use App\Domain\Enrollment\OverloadVerdict;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DFD 2.4 "Finalize Subject Selection" (FR-ENR-007–009): atomically records
 * the verified subject selection as a Pending PEF record.
 * `StoreEnrollmentRequest` has already re-validated every submitted section
 * against a freshly built eligible pool before this executes — this action
 * only writes.
 *
 * No assessment and no queue ticket are created here, for any student: both
 * come only once the Registrar approves (`TransitionEnrollment`'s
 * `registrar_approve`), so a student sees "waiting for approval" with no
 * queue number until then. An irregular or overload submission first waits
 * at `pending_program_head_approval`; every other submission (a prescribed
 * block, or a regular-category student) goes straight to
 * `pending_registrar_approval`. This reverses the 2026-09-16 rule that
 * auto-approved regular submissions to payment (ADR 0030).
 *
 * All writes happen in one transaction (FR-ENR-007's "submit atomically" and
 * its acceptance criterion: exactly one enrollment, the selected enrollment
 * subjects, one audit entry, and the appropriate notification).
 * `enrollments.active_academic_term_id`'s generated-column uniqueness
 * constraint is what actually prevents a duplicate active enrollment at the
 * database layer; the Form Request's pre-check exists only to turn that into
 * a clean 422 instead of a raw QueryException.
 *
 * Seats are re-checked here, inside the transaction, under row locks — the
 * Form Request's pool-based checks are a fast pre-check, not the
 * authoritative one, since a section's remaining seats can change between
 * the pool being built and this write running. A block submission locks
 * every one of its sections together in one deterministic order, so the
 * whole block is all-or-nothing: if any single subject in the block has
 * lost its last seat since the pool was built, nothing in the block is
 * written.
 */
final readonly class SubmitEnrollment
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
    ) {}

    /**
     * `$notify` is false only for a freshman's pay-first enrollment (ADR 0043), which is
     * approved and paid in the same transaction: nobody is waiting on an approval, so
     * "pending Registrar approval" notices would be wrong. The audit entry is always written.
     *
     * @param  list<int>  $sectionIds
     */
    public function execute(
        StudentProfile $student,
        AcademicTerm $term,
        array $sectionIds,
        User $actor,
        AuditRequestContext $context,
        ?string $blockCode = null,
        bool $notify = true,
    ): Enrollment {
        return DB::transaction(function () use ($student, $term, $sectionIds, $actor, $context, $blockCode, $notify): Enrollment {
            // Lock order is PROFILE, then sections: the order `ConfirmFreshmanPayment` and
            // `TransitionEnrollment` take. The routing below must read the payment status from the
            // locked row, not from the profile the request loaded earlier: a first payment confirmed
            // in between would otherwise leave a paid transferee's new enrollment stuck at the hold.
            $lockedStudent = StudentProfile::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            // `orderBy('id')` gives every concurrent submission the same
            // lock order; without it, two submissions sharing a subject
            // could lock in opposite orders and deadlock instead of one
            // simply waiting for the other.
            $sections = Section::query()
                ->whereIn('id', $sectionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->with('subject')
                ->get();

            $oversold = $sections->first(fn (Section $section): bool => $section->remainingSeats() < 1);
            if ($oversold !== null) {
                throw ValidationException::withMessages([
                    'sections' => "{$oversold->section_code} ({$oversold->subject->code}) no longer has an open seat. Refresh and try again.",
                ]);
            }

            $totalUnits = (float) $sections->sum(fn (Section $section): float => $section->subject->units);

            // Recomputed from the locked, just-verified sections — not
            // trusted from StoreEnrollmentRequest's own pre-check — the
            // same authoritative-server pattern the oversold check above
            // already follows. A Rejected verdict here would mean the
            // section list changed between validation and this lock (the
            // same narrow race the oversold check guards against); it is
            // surfaced the identical way.
            $overloadVerdict = OverloadEvaluator::evaluate(
                $totalUnits,
                self::numericConfigValue('enrollment.max_regular_units'),
                self::numericConfigValue('enrollment.overload_max_units'),
            );
            // Regular students taking their prescribed curriculum block sections are
            // enrolling in their official load and are exempt from overload rejection.
            $isBlockSubmission = $blockCode !== null;
            $isRegularCategory = $student->enrollment_category === EnrollmentCategory::Regular->value;

            $maxRegularUnits = $student->curriculum?->effectiveRegularUnits()
                ?? self::numericConfigValue('enrollment.max_regular_units');
            $overloadMaxUnits = $student->curriculum?->effectiveMaxUnits()
                ?? self::numericConfigValue('enrollment.overload_max_units');

            $overloadVerdict = ($isBlockSubmission || $isRegularCategory)
                ? OverloadVerdict::WithinRegular
                : OverloadEvaluator::evaluate($totalUnits, $maxRegularUnits, $overloadMaxUnits);

            // An irregular student may submit a load over the maximum once they have confirmed they are sure
            // (`StoreEnrollmentRequest` asks for that confirmation). It is not refused: it goes to the Program Chair
            // like any overload, and the enrollment keeps the maximum it was measured against (`unit_maximum`) so the
            // Chair can see it is over and set the right units and schedule.
            if ($overloadVerdict === OverloadVerdict::Rejected) {
                $overloadVerdict = OverloadVerdict::RequiresApproval;
            }

            // Regular students taking their prescribed block (or classified
            // regular) go straight to the Registrar; everyone else, irregular
            // or overload, is checked by the Program Head first.
            $needsProgramHead = ! ($isBlockSubmission || $isRegularCategory)
                || $overloadVerdict === OverloadVerdict::RequiresApproval;

            // A transferee who has not paid waits at the payment hold instead of
            // joining the Registrar's queue (ADR 0043, amended 2026-10-05).
            $afterReview = EnrollmentRouting::statusAfterReview($lockedStudent);
            $awaitsPayment = $afterReview === EnrollmentStatus::AwaitingFirstPayment;

            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'academic_term_id' => $term->id,
                'status' => $needsProgramHead
                    ? EnrollmentStatus::PendingProgramHeadApproval
                    : $afterReview,
                'total_units' => $totalUnits,
                'requires_overload_approval' => $overloadVerdict === OverloadVerdict::RequiresApproval,
                'unit_maximum' => $overloadMaxUnits ?? $maxRegularUnits,
                'submitted_at' => now(),
            ]);

            foreach ($sectionIds as $sectionId) {
                EnrollmentSubject::create([
                    'enrollment_id' => $enrollment->id,
                    'section_id' => $sectionId,
                    'status' => EnrollmentSubjectStatus::Selected,
                ]);
            }

            Section::query()->whereIn('id', $sectionIds)->increment('enrolled_count');

            $this->auditRecorder->record(
                $actor,
                AuditAction::ENROLLMENT_SUBMITTED,
                AuditableType::ENROLLMENT,
                $enrollment->id,
                null,
                [
                    'student_id' => $enrollment->student_id,
                    'academic_term_id' => $enrollment->academic_term_id,
                    'section_ids' => $sectionIds,
                    'block_code' => $blockCode,
                    'total_units' => $enrollment->total_units,
                    'requires_overload_approval' => $enrollment->requires_overload_approval,
                ],
                null,
                $context,
            );

            if (! $notify) {
                // Notices suppressed: see the docblock above.
            } elseif (! $needsProgramHead && $awaitsPayment) {
                // The Registrar is not told: nothing reaches them before the first payment.
                Notification::create([
                    'user_id' => $actor->id,
                    'type' => NotificationType::EnrollmentSubmitted,
                    'message' => sprintf(
                        'Your enrollment for %s %s has been saved. Take your queue number to the school Cashier and make your first payment; once you have paid, it goes to the Registrar for approval.',
                        $term->school_year,
                        $term->semester,
                    ),
                ]);
            } elseif (! $needsProgramHead) {
                $this->notificationRecorder->recordManyForRoles(
                    [UserRole::RegistrarStaff, UserRole::RegistrarHead],
                    NotificationType::EnrollmentSubmitted,
                    sprintf(
                        'Student %s submitted an enrollment for %s %s and is awaiting Registrar approval.',
                        $student->student_number,
                        $term->school_year,
                        $term->semester,
                    ),
                );

                Notification::create([
                    'user_id' => $actor->id,
                    'type' => NotificationType::EnrollmentSubmitted,
                    'message' => sprintf(
                        'Your enrollment for %s %s has been submitted and is pending Registrar approval. Once approved, please proceed to the school Cashier kiosk on campus to claim your queuing ticket.',
                        $term->school_year,
                        $term->semester,
                    ),
                ]);
            } else {
                Notification::create([
                    'user_id' => $actor->id,
                    'type' => NotificationType::EnrollmentSubmitted,
                    'message' => $awaitsPayment
                        ? sprintf(
                            'Your enrollment for %s %s has been submitted and is pending Program Head schedule review. After that, take your queue number to the school Cashier and make your first payment; the Registrar approves once you have paid.',
                            $term->school_year,
                            $term->semester,
                        )
                        : sprintf(
                            'Your enrollment for %s %s has been submitted and is pending Program Head schedule review, then Registrar approval. Once approved, please proceed to the school Cashier kiosk on campus to claim your queuing ticket.',
                            $term->school_year,
                            $term->semester,
                        ),
                ]);

                $this->notificationRecorder->recordManyForRole(
                    UserRole::ProgramChair,
                    NotificationType::EnrollmentSubmitted,
                    sprintf(
                        'Irregular student %s submitted an enrollment for %s %s and is awaiting Program Head schedule checking.',
                        $student->student_number,
                        $term->school_year,
                        $term->semester,
                    ),
                );
            }

            return $enrollment->refresh()->load(['student', 'enrollmentSubjects.section.subject', 'enrollmentSubjects.section.professor', 'queueTicket', 'assessment.items']);
        });
    }

    private static function numericConfigValue(string $key): ?float
    {
        $raw = config($key);

        return is_numeric($raw) ? (float) $raw : null;
    }
}
