<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * ADR 0046. The Registrar Head owns the full-payment discount (every Accounting user is staff
 * and Accounting has no head). Accounting Staff may read it, so the Cashier can be told what
 * is on offer, but never change it.
 */
final class PaymentDiscountSettingPolicy
{
    public function view(User $user): bool
    {
        return in_array($user->role, [UserRole::RegistrarHead, UserRole::AccountingStaff], true);
    }

    public function update(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }
}
