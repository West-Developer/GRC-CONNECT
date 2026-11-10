<?php

namespace App\Actions\Enrollment;

use App\Actions\Billing\BuildStudentAccountBalance;
use App\Models\Enrollment;
use App\Models\StudentProfile;

/**
 * When the Cashier needs a promissory note on file (owner, 2026-10-13). A note is only for a
 * student who carries a previous bill: an unpaid balance on one of their OTHER enrollments. A
 * downpayment on this term's own assessment, with nothing owed from before, never needs one:
 * the rest of the term simply stays on the account. So a note is required exactly when the
 * payment is partial (below the term's assessment) AND a previous bill is still open.
 *
 * One place for the rule, so the form request that refuses the payment and the action that
 * confirms it cannot drift apart. The previous balance is read from `BuildStudentAccountBalance`,
 * the same authoritative account view the Cashier and the Student see, never a stored figure.
 */
final readonly class PromissoryNoteRule
{
    public function __construct(private BuildStudentAccountBalance $accountBalance) {}

    /**
     * What the student still owes from enrollments other than `$enrollment` (this term's own
     * assessment is not a previous bill).
     *
     * @return numeric-string
     */
    public function previousBalance(Enrollment $enrollment): string
    {
        $student = StudentProfile::query()->findOrFail($enrollment->student_id);
        $balance = $this->accountBalance->execute($student);

        $previous = '0.00';

        foreach ($balance->entries as $entry) {
            if ($entry->enrollmentId !== $enrollment->id) {
                $previous = bcadd($previous, $entry->outstandingBalance, 2);
            }
        }

        return $previous;
    }

    /**
     * @param  mixed  $amount  the amount received (a number or a decimal string); null when none was sent
     * @param  mixed  $totalDue  this term's assessment; null when it has none
     */
    public function isRequired(Enrollment $enrollment, mixed $amount, mixed $totalDue): bool
    {
        if (! is_numeric($amount) || ! is_numeric($totalDue)) {
            return false;
        }

        if (bccomp((string) $amount, (string) $totalDue, 2) !== -1) {
            return false;
        }

        return bccomp($this->previousBalance($enrollment), '0.00', 2) === 1;
    }
}
