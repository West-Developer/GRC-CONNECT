<?php

namespace App\Actions\Billing;

use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Accounting's own payment history — the counterpart to `ListQueueTickets`,
 * but reading `payments` directly rather than `Enrollment::scopeVisibleTo`
 * (which drops a row the instant `ConfirmPayment` moves the enrollment out
 * of `pending_payment`). See `App\Policies\PaymentPolicy` for who may call
 * this at all; there is no further per-row scoping between the two allowed
 * roles.
 */
final readonly class ListPayments
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Payment>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $confirmedOn = isset($filters['confirmed_on']) ? (string) $filters['confirmed_on'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return Payment::query()
            ->with(['enrollment.student'])
            ->when($confirmedOn !== null, fn ($query) => $query->whereDate('confirmed_at', $confirmedOn))
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }
}
