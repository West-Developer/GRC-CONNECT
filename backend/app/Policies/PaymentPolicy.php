<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * PRD §5.3: Accounting confirms payments and needs its own record beyond
 * the instant `Enrollment::scopeVisibleTo` drops a row out of the
 * `pending_payment` filter (see `App\Actions\Enrollment\ConfirmPayment` —
 * confirmation flips the enrollment's status the same moment the payment is
 * created). Registrar Head keeps the same institution-wide oversight it
 * already holds over `void` and `academic-records`. Neither role is
 * per-payment-scoped — `App\Actions\Billing\ListPayments` returns every
 * row for both, the Policy is the only gate.
 */
final class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::AccountingStaff,
            UserRole::RegistrarHead,
        ], true);
    }
}
