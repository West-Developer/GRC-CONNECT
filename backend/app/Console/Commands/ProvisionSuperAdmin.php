<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class ProvisionSuperAdmin extends Command
{
    protected $signature = 'super-admin:provision
        {--name= : Name of the super admin}
        {--password= : Optional initial password for password login}
        {--deactivate : Deactivate the super admin account and revoke all sessions}';

    protected $description = 'Provision or deactivate the designated Super Admin account';

    public function handle(AuditRecorder $auditRecorder): int
    {
        $email = (string) config('super_admin.email');

        if (trim($email) === '') {
            $this->error('SUPER_ADMIN_EMAIL is not configured.');

            return self::FAILURE;
        }

        $deactivate = (bool) $this->option('deactivate');

        return DB::transaction(function () use ($email, $deactivate, $auditRecorder): int {
            $existing = User::where('email', $email)->lockForUpdate()->first();

            $context = new AuditRequestContext(
                (string) Str::uuid(),
                null,
            );

            if ($deactivate) {
                if ($existing === null) {
                    $this->error("Super admin account with email {$email} does not exist.");

                    return self::FAILURE;
                }

                if (! $existing->isSuperAdmin()) {
                    $this->error("Account with email {$email} is not a super admin.");

                    return self::FAILURE;
                }

                $beforeValues = [
                    'status' => $existing->status->value,
                ];

                $existing->update([
                    'status' => UserStatus::Disabled,
                ]);

                $existing->tokens()->delete();

                $auditRecorder->record(
                    $existing,
                    AuditAction::SUPER_ADMIN_DEACTIVATED,
                    AuditableType::USER_ACCOUNT,
                    $existing->id,
                    $beforeValues,
                    ['status' => UserStatus::Disabled->value],
                    null,
                    $context,
                );

                $this->info("Super admin account {$email} has been deactivated.");

                return self::SUCCESS;
            }

            if ($existing !== null) {
                if (! $existing->isSuperAdmin()) {
                    $this->error("Account with email {$email} already exists with role {$existing->role->value}.");

                    return self::FAILURE;
                }

                $beforeValues = [
                    'status' => $existing->status->value,
                ];

                $updates = [
                    'status' => UserStatus::Active,
                    'account_setup_completed_at' => $existing->account_setup_completed_at ?? now(),
                ];

                $password = (string) $this->option('password');
                if ($password !== '') {
                    $updates['password'] = Hash::make($password);
                    $updates['last_otp_verified_at'] = now();
                }

                $existing->update($updates);

                $auditRecorder->record(
                    $existing,
                    AuditAction::SUPER_ADMIN_PROVISIONED,
                    AuditableType::USER_ACCOUNT,
                    $existing->id,
                    $beforeValues,
                    ['status' => UserStatus::Active->value],
                    null,
                    $context,
                );

                $this->info("Super admin account {$email} re-activated.");

                return self::SUCCESS;
            }

            $name = (string) ($this->option('name') ?: 'Super Admin');
            $password = (string) $this->option('password');
            $hashedPassword = $password !== '' ? Hash::make($password) : Str::random(64);

            $admin = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $hashedPassword,
                'role' => UserRole::SuperAdmin,
                'status' => UserStatus::Active,
                'account_setup_completed_at' => now(),
                'last_otp_verified_at' => $password !== '' ? now() : null,
            ]);

            $auditRecorder->record(
                $admin,
                AuditAction::SUPER_ADMIN_PROVISIONED,
                AuditableType::USER_ACCOUNT,
                $admin->id,
                null,
                [
                    'role' => UserRole::SuperAdmin->value,
                    'status' => UserStatus::Active->value,
                ],
                null,
                $context,
            );

            $this->info("Super admin account {$email} provisioned.");

            return self::SUCCESS;
        });
    }
}
