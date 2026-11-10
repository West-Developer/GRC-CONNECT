<?php

namespace App\Actions\Billing;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cashier history joins the immutable enrollment-confirmation receipt and the
 * separately audited balance-payment ledger into one read-only timeline.
 * `payments` remains available through its own endpoint for compatibility;
 * this action deliberately creates a new normalized representation instead
 * of changing that established API contract.
 */
final readonly class ListCashierTransactions
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $studentNumber = isset($filters['student_number']) ? trim((string) $filters['student_number']) : null;
        $processedOn = isset($filters['processed_on']) ? (string) $filters['processed_on'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        $enrollmentPayments = $this->applyFilters(
            DB::table('payments')
                ->join('enrollments', 'enrollments.id', '=', 'payments.enrollment_id')
                ->join('student_profiles', 'student_profiles.id', '=', 'enrollments.student_id')
                ->join('users', 'users.id', '=', 'student_profiles.user_id')
                ->select([
                    'student_profiles.id as student_id',
                    'users.name as student_name',
                    'student_profiles.student_number',
                    'enrollments.id as enrollment_id',
                    'payments.amount',
                    'payments.confirmed_at as processed_at',
                ])
                ->selectRaw("CONCAT('enrollment_payment:', payments.id) as id")
                ->selectRaw("'enrollment_payment' as transaction_type"),
            $studentNumber,
            $processedOn,
            'payments.confirmed_at',
        );

        $accountPayments = $this->applyFilters(
            DB::table('account_payments')
                ->leftJoin('enrollments', 'enrollments.id', '=', 'account_payments.enrollment_id')
                ->join('student_profiles', 'student_profiles.id', '=', 'account_payments.student_id')
                ->join('users', 'users.id', '=', 'student_profiles.user_id')
                ->select([
                    'student_profiles.id as student_id',
                    'users.name as student_name',
                    'student_profiles.student_number',
                    'enrollments.id as enrollment_id',
                    'account_payments.amount',
                    'account_payments.received_at as processed_at',
                ])
                ->selectRaw("CONCAT('account_payment:', account_payments.id) as id")
                ->selectRaw("'account_payment' as transaction_type"),
            $studentNumber,
            $processedOn,
            'account_payments.received_at',
        );

        return DB::query()
            ->fromSub($enrollmentPayments->unionAll($accountPayments), 'cashier_transactions')
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }

    private function applyFilters(
        Builder $query,
        ?string $studentNumber,
        ?string $processedOn,
        string $processedAtColumn,
    ): Builder {
        return $query
            ->when($studentNumber !== null && $studentNumber !== '', fn (Builder $query) => $query->where('student_profiles.student_number', $studentNumber))
            ->when($processedOn !== null, fn (Builder $query) => $query->whereDate($processedAtColumn, $processedOn));
    }
}
