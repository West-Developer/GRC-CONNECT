<?php

namespace Tests\Feature\Console;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * A Transferee or Returnee whose 7 days ran out without the Registrar approving an enrollment is put
 * back to a pending account (ADR 0048); Admission's setup invitation then admits them again.
 */
final class DeactivateOverdueStudentAccountsCommandTest extends TestCase
{
    use BuildsFreshmen;
    use RefreshDatabase;

    private int $sequence = 0;

    /** An account that finished its setup and signed in, with a deadline `$deadline` from now (negative: ago). */
    private function activeStudent(?string $deadline, StudentType $type = StudentType::Transferee): StudentProfile
    {
        $this->sequence++;
        $profile = $this->freshmanProfile(sprintf('2026-10-%05d', $this->sequence), $type);
        $profile->user->forceFill([
            'status' => UserStatus::Active, 'account_setup_completed_at' => now()->subDays(8),
        ])->save();
        $profile->user->createToken('browser');
        $profile->forceFill(['enrollment_deadline_at' => $deadline === null ? null : now()->modify($deadline)])->save();

        return $profile->refresh();
    }

    public function test_an_overdue_account_goes_back_to_pending_loses_its_tokens_and_is_audited(): void
    {
        $this->staffUser(UserRole::RegistrarHead, 'head@grc.test');
        $profile = $this->activeStudent('-1 minute');
        $deadline = $profile->enrollment_deadline_at;

        $this->artisan('students:deactivate-overdue')->assertSuccessful();

        $user = $profile->user->fresh();
        self::assertSame(UserStatus::Disabled, $user->status);
        self::assertNull($user->account_setup_completed_at, 'Admission can only admit them again through the setup invitation');
        self::assertSame(0, $user->tokens()->count());
        self::assertEquals($deadline, $profile->fresh()->enrollment_deadline_at, 'the passed date is kept so the next invitation restarts it');
        $audit = AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_DEACTIVATED)->sole();
        self::assertSame($user->id, $audit->auditable_id);
        self::assertStringContainsString('7-day', (string) $audit->reason);
    }

    public function test_a_returnee_is_deactivated_the_same_way(): void
    {
        $this->staffUser(UserRole::RegistrarHead, 'head@grc.test');
        $profile = $this->activeStudent('-2 days', StudentType::Returnee);

        $this->artisan('students:deactivate-overdue')->assertSuccessful();

        self::assertSame(UserStatus::Disabled, $profile->user->fresh()->status);
    }

    public function test_accounts_with_time_left_or_without_a_deadline_are_left_alone(): void
    {
        $this->staffUser(UserRole::RegistrarHead, 'head@grc.test');
        $due = $this->activeStudent('+1 minute');
        $legacy = $this->activeStudent(null);
        $approved = $this->activeStudent(null, StudentType::Returnee);

        $this->artisan('students:deactivate-overdue')->assertSuccessful();

        foreach ([$due, $legacy, $approved] as $profile) {
            self::assertSame(UserStatus::Active, $profile->user->fresh()->status);
            self::assertSame(1, $profile->user->tokens()->count());
        }
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_DEACTIVATED)->count());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->staffUser(UserRole::RegistrarHead, 'head@grc.test');
        $this->activeStudent('-1 hour');
        // An account that never finished its setup is already unusable and is not touched either.
        $neverSetUp = $this->freshmanProfile('2026-10-09999', StudentType::Transferee);
        $neverSetUp->forceFill(['enrollment_deadline_at' => now()->subDay()])->save();

        $this->artisan('students:deactivate-overdue')->assertSuccessful();
        $this->artisan('students:deactivate-overdue')->assertSuccessful();

        self::assertSame(1, AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_DEACTIVATED)->count());
    }

    public function test_a_dry_run_lists_the_accounts_and_changes_nothing(): void
    {
        $this->staffUser(UserRole::RegistrarHead, 'head@grc.test');
        $profile = $this->activeStudent('-1 hour');

        $this->artisan('students:deactivate-overdue', ['--dry-run' => true])
            ->expectsOutputToContain($profile->student_number)
            ->assertSuccessful();

        self::assertSame(UserStatus::Active, $profile->user->fresh()->status);
        self::assertSame(1, $profile->user->tokens()->count());
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_DEACTIVATED)->count());
    }

    public function test_an_approval_that_lands_first_wins(): void
    {
        $this->staffUser(UserRole::RegistrarHead, 'head@grc.test');
        $profile = $this->activeStudent('-1 hour');
        // The Registrar approved a moment before the hourly run: the deadline is already gone.
        StudentProfile::query()->whereKey($profile->id)->update(['enrollment_deadline_at' => null]);

        $this->artisan('students:deactivate-overdue')->assertSuccessful();

        self::assertSame(UserStatus::Active, $profile->user->fresh()->status);
    }

    public function test_it_is_scheduled_hourly(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'students:deactivate-overdue'));

        self::assertCount(1, $events);
        self::assertSame('0 * * * *', $events->first()->expression);
    }

    public function test_it_fails_without_a_system_actor_instead_of_deactivating_without_an_audit_trail(): void
    {
        $profile = $this->activeStudent('-1 hour');
        User::query()->where('role', UserRole::RegistrarHead)->delete();

        $this->artisan('students:deactivate-overdue')->assertFailed();

        self::assertSame(UserStatus::Active, $profile->user->fresh()->status);
    }
}
