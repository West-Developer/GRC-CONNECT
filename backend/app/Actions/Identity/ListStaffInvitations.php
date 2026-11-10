<?php

namespace App\Actions\Identity;

use App\Domain\Identity\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every account across the roles a Registrar Head may invite, newest
 * first — pending invitations and already-activated staff alike, so the
 * Registrar Head sees the whole invited roster's setup status in one
 * place. Unlike `ListFacultyInvitations`, this is not scoped to one
 * college — the Registrar Head's invite reach spans every role in
 * `UserRole::registrarInvitableCases()`.
 */
final class ListStaffInvitations
{
    /** @return Collection<int, User> */
    public function handle(): Collection
    {
        return User::query()
            ->whereIn('role', array_map(
                fn (UserRole $role): string => $role->value,
                UserRole::registrarInvitableCases(),
            ))
            ->orderByDesc('created_at')
            ->get();
    }
}
