<?php

namespace App\Console\Commands;

use App\Actions\Identity\MergeDuplicateFacultyAccounts;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

final class MergeDuplicateFacultyAccountsCommand extends Command
{
    protected $signature = 'faculty:merge-duplicates
        {--apply : Perform the merge. Without this flag it is a dry run and writes nothing}
        {--rollback= : Path to a manifest written by an earlier --apply, to undo that merge}
        {--details : List every matched and skipped legacy account instead of a summary}
        {--manifest-dir= : Where --apply writes its manifest (default storage/app/faculty-merge)}';

    protected $description = 'Merge legacy @grc.test faculty accounts into the credentialed account of the same professor (dry run by default)';

    public function handle(MergeDuplicateFacultyAccounts $merger): int
    {
        $rollback = $this->option('rollback');

        if (is_string($rollback) && $rollback !== '') {
            return $this->rollback($merger, $rollback);
        }

        $apply = (bool) $this->option('apply');
        $plan = $merger->plan();

        $this->report($plan);

        if (! $apply) {
            $this->warn('Dry run: nothing was written. Re-run with --apply to merge the accounts above.');

            return self::SUCCESS;
        }

        $actor = $this->systemActor();
        if ($actor === null) {
            $this->error('No Registrar Head account found to act as the audit actor for this merge.');

            return self::FAILURE;
        }

        $manifest = $merger->apply($actor, new AuditRequestContext('console:faculty:merge-duplicates', null));

        $directory = is_string($this->option('manifest-dir')) && $this->option('manifest-dir') !== ''
            ? $this->option('manifest-dir')
            : storage_path('app/faculty-merge');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'faculty-merge-'.now()->format('Ymd-His-v').'.json';
        File::put($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->info(sprintf('Merged %d legacy account(s) into their credentialed accounts.', count($manifest['pairs'])));
        $this->line("Manifest: {$path}");
        $this->line("Undo with: php artisan faculty:merge-duplicates --rollback=\"{$path}\"");

        return self::SUCCESS;
    }

    private function rollback(MergeDuplicateFacultyAccounts $merger, string $path): int
    {
        if (! File::exists($path)) {
            $this->error("Manifest not found: {$path}");

            return self::FAILURE;
        }

        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('The manifest is not valid JSON.');

            return self::FAILURE;
        }

        $actor = $this->systemActor();
        if ($actor === null) {
            $this->error('No Registrar Head account found to act as the audit actor for this rollback.');

            return self::FAILURE;
        }

        try {
            $result = $merger->rollback($manifest, $actor, new AuditRequestContext('console:faculty:merge-duplicates', null));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Restored %d legacy account(s) and moved %d row(s) back.',
            $result['accounts'],
            $result['rows'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     pairs: list<array{legacy_id: int, target_id: int, name: string, college: string, rows: array<string, int>}>,
     *     skipped: list<array{legacy_id: int, name: string, college: ?string, reason: string, sections: int}>
     * }  $plan
     */
    private function report(array $plan): void
    {
        $details = (bool) $this->option('details');

        $totals = array_fill_keys(array_keys(MergeDuplicateFacultyAccounts::REPOINTED), 0);
        foreach ($plan['pairs'] as $pair) {
            foreach ($pair['rows'] as $table => $count) {
                $totals[$table] += $count;
            }
        }

        $this->info(sprintf('%d legacy account(s) have exactly one credentialed twin (same name and college).', count($plan['pairs'])));
        $this->table(
            ['Table', 'Rows that would move to the twin'],
            array_map(fn (string $table, int $count): array => [$table, $count], array_keys($totals), $totals),
        );

        $pairRows = array_map(
            fn (array $pair): array => [
                $pair['legacy_id'], '→ '.$pair['target_id'], $pair['name'], strtoupper($pair['college']), $pair['rows']['sections'],
            ],
            $plan['pairs'],
        );
        $this->table(['Legacy', 'Twin', 'Name', 'College', 'Sections'], $details ? $pairRows : array_slice($pairRows, 0, 10));
        if (! $details && count($pairRows) > 10) {
            $this->line(sprintf('… and %d more (use --details to list all).', count($pairRows) - 10));
        }

        $this->newLine();
        $this->warn(sprintf('%d legacy account(s) were NOT matched and will not be touched:', count($plan['skipped'])));

        $byReason = [];
        foreach ($plan['skipped'] as $skipped) {
            $byReason[$skipped['reason']]['accounts'] = ($byReason[$skipped['reason']]['accounts'] ?? 0) + 1;
            $byReason[$skipped['reason']]['sections'] = ($byReason[$skipped['reason']]['sections'] ?? 0) + $skipped['sections'];
        }
        $this->table(
            ['Reason', 'Accounts', 'Sections they own'],
            array_map(fn (string $reason, array $row): array => [$reason, $row['accounts'], $row['sections']], array_keys($byReason), $byReason),
        );

        if ($details) {
            $this->table(
                ['Legacy', 'Name', 'College', 'Sections', 'Reason'],
                array_map(fn (array $s): array => [$s['legacy_id'], $s['name'], strtoupper((string) $s['college']), $s['sections'], $s['reason']], $plan['skipped']),
            );
        }
    }

    private function systemActor(): ?User
    {
        return User::query()->where('role', UserRole::RegistrarHead)->orderBy('id')->first();
    }
}
