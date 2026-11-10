<?php

namespace App\Policies;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Identity\UserRole;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use App\Models\User;

/**
 * Credit mapping is the Program Chair's work; the Registrar Head decides it
 * (ADR 0026, amended; it superseded PRD §3.8's "Registrar Staff process
 * transferee credit mappings"). A Student saves a list and sends it, a Program
 * Chair maps and endorses each credit, and the Registrar Head approves, rejects
 * or returns the endorsed ones. Registrar Staff keep the read-only "keeper of
 * the official record" visibility they have over enrollments, grades and
 * withdrawals.
 *
 * "Which rows" is `TransfereeCredit::scopeVisibleTo`; these are the
 * role-level gates, plus the one ownership check a Program Chair needs (the
 * credit's student must be in their own college), which `update` makes when
 * it is given the credit.
 */
final class TransfereeCreditPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Student,
            UserRole::ProgramChair,
            UserRole::RegistrarStaff,
            UserRole::RegistrarHead,
        ], true);
    }

    /**
     * A Student requests for themselves (the Action pins the student to the
     * caller); a Program Chair records one for a student in their college.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::Student, UserRole::ProgramChair], true);
    }

    /**
     * A Student sends their own saved list to the Program Chair (the Action
     * pins it to the caller).
     */
    public function submit(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    /**
     * The Program Chair's student-level work: the bulk suggestions and sending a
     * whole student to the Registrar Head. Only for a student of the Chair's own
     * college; a Chair without an assigned college is unscoped, the same fallback
     * `update` and `TransfereeCredit::scopeVisibleTo` use.
     */
    public function manageStudent(User $user, StudentProfile $student): bool
    {
        if ($user->role !== UserRole::ProgramChair) {
            return false;
        }

        if ($user->college === null) {
            return true;
        }

        $student->loadMissing('program');

        return $student->program->college === $user->college;
    }

    /**
     * A Student edits only their own draft. The Action re-checks that it is still
     * a draft under the row lock.
     */
    public function editDraft(User $user, TransfereeCredit $credit): bool
    {
        if ($user->role !== UserRole::Student) {
            return false;
        }

        $credit->loadMissing('student');

        return $credit->student->user_id === $user->id;
    }

    /**
     * A Student removes only their own draft (`DeleteTransfereeCreditDraft`).
     */
    public function deleteDraft(User $user, TransfereeCredit $credit): bool
    {
        return $this->editDraft($user, $credit);
    }

    /**
     * Mapping, editing, endorsing, declining and asking for suggestions: the
     * Program Chair's work, on their own college's students only.
     */
    public function update(User $user, ?TransfereeCredit $credit = null): bool
    {
        if ($user->role !== UserRole::ProgramChair) {
            return false;
        }

        // A draft is the student's private list: the Program Chair has no access to one.
        if ($credit !== null && $credit->status === TransfereeCreditStatus::Draft) {
            return false;
        }

        return $credit === null || $this->chairOwns($user, $credit);
    }

    /**
     * Approve, reject or return an endorsed credit. The Registrar Head only:
     * never the Program Chair (who endorses) and never Registrar Staff (read-only).
     */
    public function decide(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }

    /**
     * A chair without an assigned college is unscoped, the same fallback
     * `TransfereeCredit::scopeVisibleTo` uses.
     */
    private function chairOwns(User $chair, TransfereeCredit $credit): bool
    {
        if ($chair->college === null) {
            return true;
        }

        $credit->loadMissing('student.program');

        return $credit->student->program->college === $chair->college;
    }
}
