<?php

namespace App\Policies;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\UserRole;
use App\Models\Enrollment;
use App\Models\User;

/**
 * Four roles may list enrollments (PRD §5.3 FR-FIN-001/005): the owning
 * Student, the Registrar Head (oversight and void), Registrar Staff (the
 * approval queue), and Accounting Staff (payment queue). "Which rows" is
 * resolved by `Enrollment::scopeVisibleTo`, the same division of labor ADR
 * 0008 establishes throughout this codebase — this Policy is the role-level
 * gate, the scope is the record-level filter.
 *
 * `decideApproval` and `void` follow ADR 0011: one `PATCH` route serves two
 * different checkpoints (the initial approval queue, and an "authorized edge
 * case" override on an already-approved-but-unpaid enrollment — PRD §3.7),
 * so there is no single `role:` middleware gating the route;
 * `EnrollmentController::update` resolves the right ability from the
 * request's `action` field, mirroring `ScheduleProposalPolicy`.
 */
final class EnrollmentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Student,
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
            UserRole::ProgramChair,
            UserRole::AccountingStaff,
            UserRole::Dean,
            UserRole::ExecutiveDirector,
        ], true);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    /**
     * Covers `registrar_approve` and `registrar_reject` — worked by Registrar Staff
     * and the Registrar Head for EVERY enrollment (ADR 0030). Approval issues the
     * assessment and transitions to pending payment.
     */
    public function decideApproval(User $user): bool
    {
        return in_array($user->role, [
            UserRole::RegistrarStaff,
            UserRole::RegistrarHead,
        ], true);
    }

    /**
     * Covers `program_head_approve` and `program_head_reject` — the first step of
     * an irregular or overload enrollment. Record-level: only the Program Head of
     * the student's own college, so a Program Head can never decide another
     * college's student.
     */
    public function decideProgramHeadApproval(User $user, Enrollment $enrollment): bool
    {
        if ($user->role !== UserRole::ProgramChair || $user->college === null) {
            return false;
        }

        return $enrollment->student->program->college?->value === $user->college->value;
    }

    /**
     * Covers `PATCH .../subjects`: the Program Head adding or removing whole
     * subjects from a student's proposed schedule while it sits at their own
     * review stage, OR an irregular student proposing a counter-schedule
     * while their enrollment is in `pending_student_review`.
     */
    public function reviseSubjects(User $user, Enrollment $enrollment): bool
    {
        if ($user->role === UserRole::ProgramChair) {
            return $this->decideProgramHeadApproval($user, $enrollment);
        }

        if ($user->role === UserRole::Student) {
            return $enrollment->student->user_id === $user->id
                && $enrollment->student->enrollment_category === 'irregular'
                && $enrollment->status === EnrollmentStatus::PendingStudentReview;
        }

        return false;
    }

    /**
     * Covers `student_accept_revision` and `student_decline_revision`: the owning
     * Student answering the Program Chair's changes to their subjects (ADR 0040).
     * The status window (`pending_student_review`) is enforced by
     * `UpdateEnrollmentRequest` and `TransitionEnrollment`.
     */
    public function respondToRevision(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $enrollment->student->user_id === $user->id;
    }

    /**
     * Covers `void`: cancelling an enrollment at the Registrar's end, at any
     * point before payment, usually because the student asked. Registrar Staff
     * and the Registrar Head may both do it (stakeholder Doc 14, ADR 0030); once
     * an enrollment is paid it is a withdrawal, not a void.
     */
    public function void(User $user): bool
    {
        return in_array($user->role, [UserRole::RegistrarHead, UserRole::RegistrarStaff], true);
    }

    /**
     * Covers `student_cancel`: the owning Student undoing their own submission
     * until the Registrar approves it (for a wrong section choice). The status
     * window itself is enforced by `UpdateEnrollmentRequest` and
     * `TransitionEnrollment`.
     */
    public function cancel(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $enrollment->student->user_id === $user->id;
    }

    /**
     * FR-FIN-007: confirming an externally received payment is Accounting
     * Staff's own action, a third checkpoint after the Registrar Head's two
     * (`decideApproval`, `void`) — no per-enrollment ownership dimension,
     * same class-level shape as `QueueTicketPolicy::update`.
     */
    public function confirmPayment(User $user): bool
    {
        return $user->role === UserRole::AccountingStaff;
    }

    public function viewCorPreview(User $user): bool
    {
        return in_array($user->role, [
            UserRole::AccountingStaff,
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
        ], true);
    }

    /**
     * Accounting may correct an assessment's financial lines before payment,
     * but never its academic enrollment data. The action enforces the
     * pending-payment/no-payment state under a database lock.
     */
    public function adjustAssessment(User $user): bool
    {
        return $user->role === UserRole::AccountingStaff;
    }

    /**
     * FR-FIN-004: only the owning Student may request withdrawal from their
     * own `enrolled` enrollment — an instance-level ownership check, unlike
     * every other ability here, because this is the first Student action
     * targeting a specific pre-existing `Enrollment` by route ID rather than
     * their own resolved `StudentProfile`. Mirrors `AcademicGradePolicy::update`'s
     * role-plus-ownership shape.
     */
    public function withdraw(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $enrollment->student->user_id === $user->id;
    }

    /**
     * Phase 7: only the owning Student may request an add/drop/change-section
     * against their own enrollment — the same instance-level ownership shape
     * as `withdraw`, since this route is also nested under a specific
     * `Enrollment` by id rather than the actor's own resolved
     * `StudentProfile`.
     */
    public function requestChange(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $enrollment->student->user_id === $user->id;
    }

    /**
     * The queue kiosk claim (a later slice adds the kiosk's own
     * authentication on top of this — this ability is the permanent
     * "who may ever produce this enrollment's one queue ticket" rule):
     * the owning Student, or any Accounting Staff member issuing on a
     * student's behalf at the front desk. Same ownership shape as
     * `withdraw`/`requestChange`, plus the front-desk override — see
     * `App\Actions\Enrollment\ClaimQueueTicket`.
     */
    public function claimQueueTicket(User $user, Enrollment $enrollment): bool
    {
        return ($user->role === UserRole::Student && $enrollment->student->user_id === $user->id)
            || $user->role === UserRole::AccountingStaff;
    }
}
