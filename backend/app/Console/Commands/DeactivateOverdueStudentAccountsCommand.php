<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A Transferee or Returnee has 7 days from Admission creating their account to get an enrollment
 * approved by the Registrar (ADR 0048). When the date on the profile passes, the account goes back
 * to the state it had before its setup: disabled, setup not completed, no login tokens. Admission
 * then admits the student again by sending the setup invitation, which also starts a new 7 days.
 * An approval clears the date, so nothing is deactivated after it.
 */
final class DeactivateOverdueStudentAccountsCommand extends Command
{
    protected $signature = 'students:deactivate-overdue
        {--dry-run : List the accounts that would be deactivated without changing anything}';

    protected $description = 'Deactivate Transferee and Returnee accounts whose 7-day enrollment deadline passed without a Registrar approval';

    public function handle(AuditRecorder $auditRecorder): int
    {
        $overdue = StudentProfile::query()
            ->whereNotNull('enrollment_deadline_at')
            ->where('enrollment_deadline_at', '<=', now())
            ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active))
            ->orderBy('id')
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No student account is past its enrollment deadline.');

            return self::SUCCESS;
        }

        $this->info("Found {$overdue->count()} student account(s) past the enrollment deadline.");

        if ($this->option('dry-run')) {
            $this->table(
                ['Profile', 'Student Number', 'Type', 'Deadline (UTC)'],
                $overdue->map(fn (StudentProfile $profile): array => [
                    $profile->id,
                    $profile->student_number,
                    $profile->student_type->value,
                    $profile->enrollment_deadline_at?->utc()->toDateTimeString() ?? '—',
                ])->all(),
            );
            $this->warn('Dry run enabled. No account was deactivated.');

            return self::SUCCESS;
        }

        $systemActor = User::query()->where('role', UserRole::RegistrarHead)->orderBy('id')->first();

        if ($systemActor === null) {
            $this->error('No Registrar Head account found to act as system actor for the deactivation audit.');

            return self::FAILURE;
        }

        $context = new AuditRequestContext('console:students:deactivate-overdue', null);
        $deactivated = 0;

        foreach ($overdue as $candidate) {
            if ($this->deactivate($candidate->id, $systemActor, $auditRecorder, $context)) {
                $deactivated++;
            }
        }

        $this->info("Deactivated {$deactivated} student account(s).");

        return self::SUCCESS;
    }

    /**
     * One account, with the profile locked first (the order the Registrar's approval takes it in), so an
     * approval that lands at this very moment either clears the date before this reads it or waits.
     */
    private function deactivate(int $profileId, User $systemActor, AuditRecorder $auditRecorder, AuditRequestContext $context): bool
    {
        return DB::transaction(function () use ($profileId, $systemActor, $auditRecorder, $context): bool {
            $profile = StudentProfile::query()->whereKey($profileId)->lockForUpdate()->first();

            if ($profile === null
                || $profile->enrollment_deadline_at === null
                || $profile->enrollment_deadline_at->isFuture()) {
                return false;
            }

            $user = User::query()->whereKey($profile->user_id)->lockForUpdate()->first();

            if ($user === null || $user->status !== UserStatus::Active) {
                return false;
            }

            $user->forceFill([
                'status' => UserStatus::Disabled,
                'account_setup_completed_at' => null,
            ])->save();
            $user->tokens()->delete();

            $auditRecorder->record(
                $systemActor,
                AuditAction::USER_ACCOUNT_DEACTIVATED,
                AuditableType::USER_ACCOUNT,
                $user->id,
                ['status' => UserStatus::Active->value],
                ['status' => UserStatus::Disabled->value],
                'The 7-day enrollment deadline passed without a Registrar approval.',
                $context,
            );

            return true;
        });
    }
}
