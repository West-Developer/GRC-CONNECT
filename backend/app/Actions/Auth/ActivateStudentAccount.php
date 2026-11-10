<?php

namespace App\Actions\Auth;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\AccountSetupCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class ActivateStudentAccount
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly AccountSetupCodes $setupCodes,
    ) {}

    public function handle(
        string $email,
        string $code,
        string $password,
        AuditRequestContext $context,
    ): User {
        $candidate = User::query()->where('email', $email)->first();

        if (
            ! $candidate instanceof User
            || $candidate->role !== UserRole::Student
            || $candidate->status !== UserStatus::Disabled
            || $candidate->account_setup_completed_at !== null
            || $candidate->archived_at !== null
        ) {
            $this->invalidCode();
        }

        // A Transferee or Returnee whose 7 days ran out cannot finish setting up an account the
        // overdue command would only deactivate again; Admission admits them again first (ADR 0048).
        $deadline = $candidate->studentProfile?->enrollment_deadline_at;
        if ($deadline !== null && $deadline->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'code' => 'The 7-day enrollment window for this account has ended. Please visit Admission to be admitted again.',
            ]);
        }

        // A wrong guess is committed inside attempt(), so it still counts
        // against the code when this method then throws.
        if (! $this->setupCodes->attempt($candidate, $code)) {
            $this->invalidCode();
        }

        $activated = DB::transaction(function () use ($candidate, $password): User {
            $locked = User::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if (
                $locked->role !== UserRole::Student
                || $locked->status !== UserStatus::Disabled
                || $locked->account_setup_completed_at !== null
                || $locked->archived_at !== null
                || ! $this->setupCodes->consume($locked)
            ) {
                $this->invalidCode();
            }

            $locked->forceFill([
                'password' => Hash::make($password),
                'status' => UserStatus::Active,
                'account_setup_completed_at' => now(),
            ])->save();
            $locked->tokens()->delete();

            return $locked->refresh();
        });

        $profileId = $activated->studentProfile()->value('id');
        $this->auditRecorder->record(
            $activated,
            AuditAction::STUDENT_ACCOUNT_ACTIVATED,
            AuditableType::STUDENT_PROFILE,
            is_numeric($profileId) ? (int) $profileId : null,
            ['account_setup_status' => 'pending'],
            ['account_setup_status' => 'active'],
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
