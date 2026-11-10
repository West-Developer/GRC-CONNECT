<?php

namespace App\Actions\Identity;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\StudentType;
use App\Models\StudentProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ListAdmissionStudentProfiles
{
    /**
     * The same three states `StudentProfileResource` reports as `account_setup_status`, so a filter always
     * matches the badge on the row: `active` (the student set the account up), `awaiting_payment` (a freshman
     * the Cashier has not confirmed, so no setup email yet), `pending` (everyone else still to set up).
     */
    public const ACCOUNT_SETUP_STATUSES = ['awaiting_payment', 'pending', 'active'];

    /**
     * Only the students Admission is processing through the pay-first intake (ADR 0043): they hold a queue
     * number, or the Cashier has confirmed their first payment. Historical records (past students imported
     * with a student type but no queue number and no payment) are left out, or they would all read "Awaiting
     * payment". This list is for acting on, so it is newest first.
     */
    public const INTAKE_PAY_FIRST = 'pay_first';

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StudentProfile>
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $studentType = isset($filters['student_type']) ? (string) $filters['student_type'] : null;
        $accountSetupStatus = isset($filters['account_setup_status']) ? (string) $filters['account_setup_status'] : null;
        $payFirstIntake = ($filters['intake'] ?? null) === self::INTAKE_PAY_FIRST;
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);

        return StudentProfile::query()
            ->with(['user', 'program', 'curriculum', 'queueTicket'])
            ->withExists('enrollments')
            // Read by `StudentProfileResource` for a paid freshman, so a page costs no query per row.
            ->withExists(['enrollments as active_enrollment_exists' => fn (Builder $enrollments) => $enrollments
                ->whereNotIn('status', [EnrollmentStatus::Rejected->value, EnrollmentStatus::Cancelled->value, EnrollmentStatus::Withdrawn->value])])
            ->withSum(['accountPayments as held_advance_payment_sum' => fn (Builder $payments) => $payments->whereNull('enrollment_id')], 'amount')
            ->when($studentType !== null, fn (Builder $query) => $query->where('student_profiles.student_type', $studentType))
            ->when($accountSetupStatus !== null, fn (Builder $query) => $this->filterByAccountSetupStatus($query, (string) $accountSetupStatus))
            ->when($payFirstIntake, fn (Builder $query) => $query->where(fn (Builder $intake): Builder => $intake
                ->whereNotNull('student_profiles.freshman_payment_confirmed_at')
                ->orHas('queueTicket')))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $pattern = '%'.$search.'%';

                $query->where(function (Builder $searchQuery) use ($pattern): void {
                    $searchQuery
                        ->where('student_number', 'like', $pattern)
                        ->orWhereHas('user', fn (Builder $userQuery): Builder => $userQuery
                            ->where('name', 'like', $pattern)
                            ->orWhere('email', 'like', $pattern));
                });
            })
            ->when(
                $payFirstIntake,
                fn (Builder $query) => $query->orderByDesc('student_profiles.id'),
                fn (Builder $query) => $query->orderBy('student_number')->orderBy('id'),
            )
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }

    /**
     * @param  Builder<StudentProfile>  $query
     * @return Builder<StudentProfile>
     */
    private function filterByAccountSetupStatus(Builder $query, string $status): Builder
    {
        if ($status === 'active') {
            return $query->whereHas('user', fn (Builder $user): Builder => $user->whereNotNull('users.account_setup_completed_at'));
        }

        $query->whereHas('user', fn (Builder $user): Builder => $user->whereNull('users.account_setup_completed_at'));

        if ($status === 'awaiting_payment') {
            return $query
                ->where('student_profiles.student_type', StudentType::Freshman->value)
                ->whereNull('student_profiles.freshman_payment_confirmed_at');
        }

        // Written out rather than `whereNot(...)`: an older profile has no student type, and `NOT (NULL AND ...)`
        // is NULL in SQL, which would silently drop it from the list.
        return $query->where(fn (Builder $profile): Builder => $profile
            ->whereNull('student_profiles.student_type')
            ->orWhere('student_profiles.student_type', '!=', StudentType::Freshman->value)
            ->orWhereNotNull('student_profiles.freshman_payment_confirmed_at'));
    }
}
