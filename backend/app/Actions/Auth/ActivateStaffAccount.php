<?php

namespace App\Actions\Auth;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\AccountSetupCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class ActivateStaffAccount
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly AccountSetupCodes $setupCodes,
    ) {}

    public function handle(
        string $email,
        string $code,
        string $password,
        string $name,
        AuditRequestContext $context,
        ?CollegeCode $college = null,
        ?string $mastersDegree = null,
    ): User {
        $candidate = User::query()->where('email', $email)->first();

        if (
            ! $candidate instanceof User
            || ! in_array($candidate->role, UserRole::superAdminInvitableCases(), true)
            || $candidate->status !== UserStatus::Disabled
            || $candidate->account_setup_completed_at !== null
        ) {
            $this->invalidCode();
        }

        // A wrong guess is committed inside attempt(), so it still counts
        // against the code when this method then throws.
        if (! $this->setupCodes->attempt($candidate, $code)) {
            $this->invalidCode();
        }

        $activated = DB::transaction(function () use ($candidate, $password, $name, $college, $mastersDegree): User {
            $locked = User::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if (
                ! in_array($locked->role, UserRole::superAdminInvitableCases(), true)
                || $locked->status !== UserStatus::Disabled
                || $locked->account_setup_completed_at !== null
                || ! $this->setupCodes->consume($locked)
            ) {
                $this->invalidCode();
            }

            $attributes = [
                'name' => $name,
                'password' => Hash::make($password),
                'status' => UserStatus::Active,
                'account_setup_completed_at' => now(),
            ];

            if ($locked->college === null && $college !== null) {
                $attributes['college'] = $college;
            }

            if ($mastersDegree !== null) {
                $attributes['masters_degree'] = $mastersDegree;
            }

            $locked->forceFill($attributes)->save();
            $locked->tokens()->delete();

            return $locked->refresh();
        });

        $this->auditRecorder->record(
            $activated,
            AuditAction::STAFF_ACCOUNT_ACTIVATED,
            AuditableType::STAFF_ACCOUNT,
            $activated->id,
            ['account_setup_status' => 'pending'],
            [
                'account_setup_status' => 'active',
                'role' => $activated->role->value,
                'college' => $activated->college?->value,
                'masters_degree' => $activated->masters_degree,
            ],
            null,
            $context,
        );

        return $activated;
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages([
            'code' => 'The setup code is invalid or expired.',
        ]);
    }
}
