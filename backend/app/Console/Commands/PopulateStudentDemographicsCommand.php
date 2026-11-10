<?php

namespace App\Console\Commands;

use App\Actions\Identity\PopulateStudentDemographics;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Console\Command;

/**
 * Fills the student details the CHED / NSTP forms need and the system never stored (ADR 0049).
 * Only empty fields are filled, demo accounts are skipped, and every generated field is recorded
 * so `--wipe` can take exactly those back. Safe to run again: a second run changes nothing.
 */
final class PopulateStudentDemographicsCommand extends Command
{
    protected $signature = 'students:populate-demographics
        {--dry-run : Show what would be filled (or wiped) without changing anything}
        {--wipe : Take back the values this command generated, and nothing a person entered}';

    protected $description = 'Fill missing student gender, birth date, citizenship, contact number and address split with generated demo values for the CHED forms';

    public function handle(PopulateStudentDemographics $populate, AuditRecorder $auditRecorder): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('wipe')) {
            $report = $populate->wipe($dryRun);
            $this->info("Students whose generated details are taken back: {$report['wiped']}.");
            $this->finish($dryRun, $auditRecorder, AuditAction::STUDENT_DEMOGRAPHICS_WIPED, ['students' => $report['wiped']]);

            return self::SUCCESS;
        }

        $report = $populate->populate($dryRun);

        $this->info("Students with something missing: {$report['considered']}. Students filled: {$report['updated']}.");
        $this->table(
            ['Field', 'Students filled'],
            array_map(fn (string $field, int $count): array => [$field, $count], array_keys($report['fields']), array_values($report['fields'])),
        );
        $this->line(sprintf(
            'Gender decided by: first name %d, name ending %d, deterministic fallback %d.',
            $report['gender']['name'],
            $report['gender']['ending'],
            $report['gender']['fallback'],
        ));

        $this->finish($dryRun, $auditRecorder, AuditAction::STUDENT_DEMOGRAPHICS_POPULATED, [
            'students' => $report['updated'],
            'fields' => $report['fields'],
            'gender' => $report['gender'],
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function finish(bool $dryRun, AuditRecorder $auditRecorder, string $action, array $after): void
    {
        if ($dryRun) {
            $this->warn('Dry run enabled. Nothing was changed.');

            return;
        }

        $actor = User::query()->where('role', UserRole::RegistrarHead)->orderBy('id')->first();

        if ($actor === null) {
            $this->warn('No Registrar Head account found to credit the audit row to; the change was made without one.');

            return;
        }

        $auditRecorder->record(
            $actor,
            $action,
            AuditableType::STUDENT_DEMOGRAPHICS,
            null,
            null,
            $after,
            'CHED / NSTP report details (console command).',
            new AuditRequestContext('console:students:populate-demographics', null),
        );
    }
}
