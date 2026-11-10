<?php

namespace App\Actions\Billing;

use App\Domain\Billing\CashierPaymentCandidate;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts lookup to a Student who is actionable in the Cashier's current
 * queue. Keeping this a read-only query ensures finding a student can never
 * silently alter ticket order or enrollment/payment state.
 *
 * A candidate's ticket may be from any `queue_date`, not just today's —
 * once a cut-off carries an unserved ticket forward (see `QueueCycle`), the
 * ticket's original claim date no longer means "not currently in the
 * line"; its `status` (`waiting`/`serving`) is what does. A candidate may
 * also have no ticket at all: any `pending_payment` enrollment is a valid
 * lookup result, so Accounting Staff can issue a first ticket on a
 * student's behalf (`App\Actions\Enrollment\ClaimQueueTicket`) instead of
 * only ever serving an existing one.
 */
final readonly class FindCashierPaymentCandidate
{
    public function execute(string $studentNumber): CashierPaymentCandidate
    {
        $term = AcademicTerm::query()
            ->where('status', AcademicTermStatus::SemesterOngoing)
            ->firstOrFail();

        $search = trim($studentNumber);

        $enrollment = Enrollment::query()
            ->with(['student.user', 'queueTicket'])
            ->where('academic_term_id', $term->id)
            ->where('status', EnrollmentStatus::PendingPayment)
            ->whereHas('student', function (Builder $query) use ($search): void {
                $query->where('student_number', $search)
                    ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                        $userQuery->where('email', $search)
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", ["%{$search}%"]);
                    });
            })
            ->where(function (Builder $query) {
                $query->whereDoesntHave('queueTicket')
                    ->orWhereHas('queueTicket', fn (Builder $query) => $query->whereIn('status', [
                        QueueTicketStatus::Waiting->value,
                        QueueTicketStatus::Serving->value,
                    ]));
            })
            ->firstOrFail();

        return new CashierPaymentCandidate($enrollment->student, $enrollment, $enrollment->queueTicket);
    }
}
