<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Folds legacy faculty accounts into the credentialed account of the same
 * professor, so the sections a student sees under one name are the sections
 * the professor sees when they log in.
 *
 * "Legacy" is a seeded `@grc.test` account. "Credentialed" is any other active
 * faculty account: the standardized `@grc.com` directory accounts, and any
 * professor invited later with a real email, so re-running the command after
 * an invitation picks that professor up too.
 *
 * Why it exists: the seeded schedule assigned sections to legacy `@grc.test`
 * accounts while the professors who actually log in hold `@grc.com` accounts
 * (Professor_Department_List.md). Faculty visibility everywhere is
 * `sections.professor_id = user.id`, so a professor could not see the sections
 * or students that the student portal showed under their name. The earlier
 * one-off `scripts/consolidate_faculty_accounts.php` fixed the sections that
 * existed then but left the legacy accounts active, so the schedule generator
 * (which draws from active faculty) kept assigning new terms to them.
 *
 * Safety rules:
 *  - A legacy account is only matched to a twin when the normalized name AND
 *    the college are equal and exactly one twin exists. Anything else is
 *    reported, never guessed.
 *  - Only professor *identity* columns move. Audit provenance such as
 *    `academic_grades.encoded_by` and notification ownership stay as they were.
 *  - Where the twin already holds a row with the same unique key, the twin's
 *    row wins and the legacy row is left where it was (reported as "kept").
 *  - The legacy account is disabled, never deleted; `EnsureUserIsActive` then
 *    blocks it and the faculty pickers stop offering it.
 *  - Every moved row id is recorded so `rollback()` can undo the merge.
 */
final readonly class MergeDuplicateFacultyAccounts
{
    public const LEGACY_DOMAIN = '@grc.test';

    /**
     * table => column holding the professor's user id.
     *
     * @var array<string, string>
     */
    public const REPOINTED = [
        'sections' => 'professor_id',
        'faculty_assignment_recommendations' => 'recommended_professor_id',
        'faculty_availabilities' => 'professor_id',
        'faculty_subject_preferences' => 'professor_id',
        'faculty_curriculum_subject_preferences' => 'professor_id',
        'faculty_specializations' => 'professor_id',
        'faculty_teaching_histories' => 'professor_id',
    ];

    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * What a merge would do, without writing anything.
     *
     * @return array{
     *     pairs: list<array{legacy_id: int, target_id: int, name: string, college: string, rows: array<string, int>}>,
     *     skipped: list<array{legacy_id: int, name: string, college: ?string, reason: string, sections: int}>
     * }
     */
    public function plan(): array
    {
        $faculty = User::query()
            ->where('role', UserRole::Faculty)
            ->where('status', UserStatus::Active)
            ->get(['id', 'name', 'email', 'college']);

        $targets = [];
        foreach ($faculty as $user) {
            if (! $this->isLegacy($user)) {
                $targets[$this->key($user)][] = $user->id;
            }
        }

        $counts = $this->rowCounts();
        $pairs = [];
        $skipped = [];

        foreach ($faculty as $legacy) {
            if (! $this->isLegacy($legacy)) {
                continue;
            }

            $sections = $counts['sections'][$legacy->id] ?? 0;

            if ($legacy->college === null) {
                $skipped[] = $this->skip($legacy, 'no college on the legacy account', $sections);

                continue;
            }

            $candidates = $targets[$this->key($legacy)] ?? [];

            if ($candidates === []) {
                $skipped[] = $this->skip($legacy, 'no credentialed account with the same name and college', $sections);
            } elseif (count($candidates) > 1) {
                $skipped[] = $this->skip($legacy, 'more than one credentialed account matches', $sections);
            } else {
                $rows = [];
                foreach (array_keys(self::REPOINTED) as $table) {
                    $rows[$table] = $counts[$table][$legacy->id] ?? 0;
                }

                $pairs[] = [
                    'legacy_id' => $legacy->id,
                    'target_id' => $candidates[0],
                    'name' => $legacy->name,
                    'college' => $legacy->college->value,
                    'rows' => $rows,
                ];
            }
        }

        return ['pairs' => $pairs, 'skipped' => $skipped];
    }

    /**
     * Performs the merge, one legacy account per transaction, and returns a
     * manifest of every row moved or kept so it can be rolled back.
     *
     * @return array{version: int, created_at: string, pairs: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function apply(User $actor, AuditRequestContext $context): array
    {
        $plan = $this->plan();
        $manifestPairs = [];

        foreach ($plan['pairs'] as $pair) {
            $manifestPairs[] = DB::transaction(function () use ($pair, $actor, $context): array {
                $legacy = User::query()->lockForUpdate()->findOrFail($pair['legacy_id']);
                $moved = [];
                $kept = [];

                foreach (self::REPOINTED as $table => $column) {
                    $result = $this->repoint($table, $column, $pair['legacy_id'], $pair['target_id']);
                    if ($result['moved'] !== []) {
                        $moved[$table] = $result['moved'];
                    }
                    if ($result['kept'] !== []) {
                        $kept[$table] = $result['kept'];
                    }
                }

                $before = ['status' => $legacy->status->value, 'deactivation_reason' => $legacy->deactivation_reason];
                $legacy->forceFill([
                    'status' => UserStatus::Disabled,
                    'deactivation_reason' => "Merged into faculty account #{$pair['target_id']} (faculty:merge-duplicates)",
                ])->save();

                $this->auditRecorder->record(
                    $actor,
                    AuditAction::FACULTY_ACCOUNT_MERGED,
                    AuditableType::FACULTY_ACCOUNT,
                    $legacy->id,
                    $before,
                    [
                        'status' => UserStatus::Disabled->value,
                        'merged_into_user_id' => $pair['target_id'],
                        'moved_rows' => array_map('count', $moved),
                        'kept_rows' => array_map('count', $kept),
                    ],
                    null,
                    $context,
                );

                return [
                    'legacy_id' => $pair['legacy_id'],
                    'target_id' => $pair['target_id'],
                    'name' => $pair['name'],
                    'college' => $pair['college'],
                    'legacy_before' => $before,
                    'moved' => $moved,
                    'kept' => $kept,
                ];
            });
        }

        return [
            'version' => 1,
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'pairs' => $manifestPairs,
            'skipped' => $plan['skipped'],
        ];
    }

    /**
     * Undoes `apply()` from its manifest: moves each recorded row back to the
     * legacy account (only if it still points at the twin, so later edits are
     * never overwritten) and restores the legacy account's status.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{accounts: int, rows: int}
     */
    public function rollback(array $manifest, User $actor, AuditRequestContext $context): array
    {
        if (($manifest['version'] ?? null) !== 1 || ! is_array($manifest['pairs'] ?? null)) {
            throw new InvalidArgumentException('This is not a faculty merge manifest this command understands.');
        }

        // Validate every pair up front so a damaged manifest fails before
        // anything is written, not halfway through the rollback.
        $pairs = array_map($this->parsePair(...), array_reverse($manifest['pairs']));

        $accounts = 0;
        $rows = 0;

        foreach ($pairs as $pair) {
            DB::transaction(function () use ($pair, $actor, $context, &$accounts, &$rows): void {
                $restored = 0;

                foreach ($pair['moved'] as $table => $ids) {
                    $column = self::REPOINTED[$table];

                    $restored += DB::table($table)
                        ->whereIn('id', $ids)
                        ->where($column, $pair['target_id'])
                        ->update([$column => $pair['legacy_id']]);
                }

                $legacy = User::query()->lockForUpdate()->findOrFail($pair['legacy_id']);
                $legacy->forceFill([
                    'status' => $pair['legacy_status'],
                    'deactivation_reason' => $pair['legacy_deactivation_reason'],
                ])->save();

                $this->auditRecorder->record(
                    $actor,
                    AuditAction::FACULTY_ACCOUNT_MERGE_ROLLED_BACK,
                    AuditableType::FACULTY_ACCOUNT,
                    $legacy->id,
                    null,
                    ['restored_from_user_id' => $pair['target_id'], 'restored_rows' => $restored],
                    null,
                    $context,
                );

                $accounts++;
                $rows += $restored;
            });
        }

        return ['accounts' => $accounts, 'rows' => $rows];
    }

    /**
     * A manifest is a file on disk, so nothing in it is trusted: each pair is
     * checked and turned into typed values before the rollback touches a row.
     *
     * @return array{legacy_id: int, target_id: int, legacy_status: UserStatus, legacy_deactivation_reason: ?string, moved: array<string, list<int>>}
     */
    private function parsePair(mixed $pair): array
    {
        $notAManifest = new InvalidArgumentException('This is not a faculty merge manifest this command understands.');

        if (! is_array($pair) || ! is_int($pair['legacy_id'] ?? null) || ! is_int($pair['target_id'] ?? null)) {
            throw $notAManifest;
        }

        $before = $pair['legacy_before'] ?? null;
        $status = is_array($before) && is_string($before['status'] ?? null) ? UserStatus::tryFrom($before['status']) : null;
        $reason = is_array($before) ? ($before['deactivation_reason'] ?? null) : null;

        if ($status === null || ($reason !== null && ! is_string($reason)) || ! is_array($pair['moved'] ?? null)) {
            throw $notAManifest;
        }

        $moved = [];
        foreach ($pair['moved'] as $table => $ids) {
            if (! is_string($table) || ! array_key_exists($table, self::REPOINTED)) {
                throw new InvalidArgumentException('Unknown table in manifest.');
            }
            if (! is_array($ids)) {
                throw $notAManifest;
            }

            $moved[$table] = array_values(array_map(intval(...), $ids));
        }

        return [
            'legacy_id' => $pair['legacy_id'],
            'target_id' => $pair['target_id'],
            'legacy_status' => $status,
            'legacy_deactivation_reason' => $reason,
            'moved' => $moved,
        ];
    }

    /**
     * Moves every row of `$table` from one professor to another. A single bulk
     * UPDATE is atomic, so if it trips a unique key the whole statement is
     * undone and each row is retried on its own; rows the twin already has an
     * equivalent of stay where they were.
     *
     * @return array{moved: list<int>, kept: list<int>}
     */
    private function repoint(string $table, string $column, int $from, int $to): array
    {
        $ids = array_values(DB::table($table)->where($column, $from)->pluck('id')->map(fn ($id): int => (int) $id)->all());

        if ($ids === []) {
            return ['moved' => [], 'kept' => []];
        }

        $touch = $table === 'sections' ? ['updated_at' => now()] : [];

        try {
            DB::table($table)->where($column, $from)->update([$column => $to] + $touch);

            return ['moved' => $ids, 'kept' => []];
        } catch (QueryException) {
            $moved = [];
            $kept = [];

            foreach ($ids as $id) {
                try {
                    DB::table($table)->where('id', $id)->update([$column => $to] + $touch);
                    $moved[] = $id;
                } catch (QueryException) {
                    $kept[] = $id;
                }
            }

            return ['moved' => $moved, 'kept' => $kept];
        }
    }

    /**
     * Rows per professor for every re-pointed table, in one grouped query each.
     *
     * @return array<string, array<int, int>>
     */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (self::REPOINTED as $table => $column) {
            $counts[$table] = DB::table($table)
                ->whereNotNull($column)
                ->selectRaw("{$column} as professor_id, count(*) as aggregate")
                ->groupBy($column)
                ->pluck('aggregate', 'professor_id')
                ->map(fn ($count): int => (int) $count)
                ->all();
        }

        return $counts;
    }

    private function isLegacy(User $user): bool
    {
        return str_ends_with(strtolower($user->email), self::LEGACY_DOMAIN);
    }

    /** Name + college, case- and whitespace-insensitive, and nothing looser. */
    private function key(User $user): string
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $user->name)));

        return $name.'|'.($user->college->value ?? '');
    }

    /**
     * @return array{legacy_id: int, name: string, college: ?string, reason: string, sections: int}
     */
    private function skip(User $legacy, string $reason, int $sections): array
    {
        return [
            'legacy_id' => $legacy->id,
            'name' => $legacy->name,
            'college' => $legacy->college?->value,
            'reason' => $reason,
            'sections' => $sections,
        ];
    }
}
