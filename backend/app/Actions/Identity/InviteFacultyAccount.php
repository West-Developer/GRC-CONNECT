<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a pending Faculty account (name is a placeholder — the professor
 * supplies their real name when they redeem the setup code) and sends the
 * setup invitation immediately, mirroring how a Student account starts
 * disabled until its own setup is completed.
 */
final class InviteFacultyAccount
{
    public function __construct(private readonly SendFacultyAccountSetupInvitation $sendInvitation) {}

    public function handle(
        string $email,
        User $actor,
        AuditRequestContext $context,
    ): User {
        if ($actor->college === null) {
            throw ValidationException::withMessages([
                'email' => 'Only a Program Head with an assigned college can invite a professor.',
            ]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This email is already registered.',
            ]);
        }

        $placeholderName = explode('@', $email)[0];

        $faculty = User::create([
            'name' => $placeholderName,
            'email' => $email,
            'password' => Str::random(64),
            'role' => UserRole::Faculty,
            'college' => $actor->college,
            'status' => UserStatus::Disabled,
            'account_setup_completed_at' => null,
        ]);

        $this->sendInvitation->handle($faculty, $actor, $context);

        return $faculty->refresh();
    }
}
