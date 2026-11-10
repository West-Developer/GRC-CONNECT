<?php

namespace App\Actions\Billing;

use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Cashier's general student search, used by the Advance Payment page.
 *
 * Unlike `FindCashierPaymentCandidate` (the payment queue's lookup, which only
 * returns a student with a `pending_payment` enrollment and an open ticket),
 * this depends on no enrollment or queue state: advance payments are for
 * students who are already enrolled, or have not started, as much as for
 * students waiting in the line. Read-only; it never changes an account.
 *
 * Matches a student number or a name fragment, or an exact email (a partial
 * email would make this a way to enumerate accounts).
 */
final readonly class FindCashierStudents
{
    public const LIMIT = 10;

    /**
     * @return Collection<int, StudentProfile>
     */
    public function execute(string $search): Collection
    {
        $term = trim($search);
        $like = '%'.addcslashes($term, '\%_').'%';

        return StudentProfile::query()
            ->with('user')
            ->join('users', 'users.id', '=', 'student_profiles.user_id')
            ->where(function (Builder $query) use ($term, $like): void {
                $query->where('student_profiles.student_number', 'like', $like)
                    ->orWhere('users.name', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', users.first_name, users.last_name) LIKE ?", [$like])
                    ->orWhere('users.email', $term);
            })
            ->orderBy('users.name')
            ->orderBy('student_profiles.id')
            ->limit(self::LIMIT)
            ->get(['student_profiles.*']);
    }
}
