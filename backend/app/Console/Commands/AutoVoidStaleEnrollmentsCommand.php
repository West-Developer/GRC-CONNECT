<?php

namespace App\Console\Commands;

use App\Actions\Enrollment\TransitionEnrollment;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\UserRole;
use App\Models\Enrollment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

final class AutoVoidStaleEnrollmentsCommand extends Command
{
    protected $signature = 'enrollments:auto-void
        {--days=3 : Minimum number of days pending payment before auto-voiding}
        {--hours= : Minimum number of hours pending payment (overrides days if provided)}
        {--dry-run : Preview which enrollments would be voided without modifying the database}';

    protected $description = 'Automatically void unpaid enrollments that exceed the payment deadline window';

    public function handle(TransitionEnrollment $transitioner): int
    {
        $days = (int) $this->option('days');
        $hoursOption = $this->option('hours');

        $cutoff = $hoursOption !== null && is_numeric($hoursOption)
            ? Carbon::now()->subHours((int) $hoursOption)
            : Carbon::now()->subDays(max(1, $days));

        $dryRun = (bool) $this->option('dry-run');

        $query = Enrollment::query()
            ->where('status', EnrollmentStatus::PendingPayment)
            ->where(function ($q) use ($cutoff) {
                $q->where('registrar_decided_at', '<=', $cutoff)
                  ->orWhere(function ($sub) use ($cutoff) {
                      $sub->whereNull('registrar_decided_at')
                          ->where('updated_at', '<=', $cutoff);
                  });
            })
            ->with(['student.user']);

        $staleEnrollments = $query->get();

        if ($staleEnrollments->isEmpty()) {
            $this->info("No stale enrollments found pending payment prior to {$cutoff->toIso8601String()}.");

            return self::SUCCESS;
        }

        $this->info("Found {$staleEnrollments->count()} stale enrollment(s) pending payment.");

        if ($dryRun) {
            $this->table(
                ['ID', 'Student Number', 'Status', 'Approved At', 'Amount Due'],
                $staleEnrollments->map(fn (Enrollment $e) => [
                    $e->id,
                    $e->student?->student_number ?? '—',
                    $e->status->value,
                    $e->registrar_decided_at?->toDateTimeString() ?? $e->updated_at->toDateTimeString(),
                    $e->assessment?->total_amount ?? '—',
                ])->all(),
            );
            $this->warn('Dry run enabled. No enrollments were voided.');

            return self::SUCCESS;
        }

        $systemActor = User::query()
            ->where('role', UserRole::RegistrarHead)
            ->first();

        if ($systemActor === null) {
            $this->error('No Registrar Head account found to act as system actor for void audit.');

            return self::FAILURE;
        }

        $context = new AuditRequestContext('console:enrollments:auto-void', null);
        $voidedCount = 0;
        $failedCount = 0;

        foreach ($staleEnrollments as $enrollment) {
            try {
                $reason = "Auto-voided due to non-payment beyond {$cutoff->diffForHumans(null, true)} deadline window.";
                $transitioner->execute($enrollment, 'void', $systemActor, $reason, $context);
                $voidedCount++;
            } catch (\Throwable $e) {
                $this->error("Failed to void enrollment #{$enrollment->id}: {$e->getMessage()}");
                $failedCount++;
            }
        }

        $this->info("Successfully auto-voided {$voidedCount} enrollment(s).");
        if ($failedCount > 0) {
            $this->warn("Failed to void {$failedCount} enrollment(s).");
        }

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}

