<?php

declare(strict_types=1);

/**
 * Puts the database back into the "presentation" state the owner demos from:
 * 2025-2026 · 2nd (term 6) is the CURRENT term, its enrollment long closed, its grades locked,
 * and nothing produced for any LATER term (or by test accounts created since) is left behind.
 *
 *   php scripts/restore_presentation_term6.php            DRY RUN: does everything inside a transaction, prints
 *                                                         what it would do, then ROLLS BACK. Writes nothing.
 *   php scripts/restore_presentation_term6.php --apply    Same, but COMMITS, and only if every guard passed.
 *
 * Options: --year=2025-2026 --sem=2nd  (the term that becomes current)
 *          --accounts-since=2026-09-25  (student accounts created on/after this date are test accounts)
 *          --max-accounts=5             (abort if more accounts than this would be deleted)
 *          --max-category-reverts=5     (abort if more real students than this had their category flipped since the archive)
 *          --since=2026-10-12T13:00:00  (where the rehearsal's lookback starts; default: two minutes before the archive)
 *          --notifications              (also delete notifications created since the term was archived)
 *
 * What it does, in order, all in ONE transaction:
 *   1. Guards: the target term exists; every one of its grades is locked; each college has a published
 *      schedule; every enrollment window has closed; it has published sections; each account to delete
 *      has no enrollment in the target term or earlier.
 *   2. Target term -> semester_ongoing with closed_at / archived_at cleared; the current-term slot -> the target.
 *   3. Every term AFTER the target term is deleted together with everything that depends on it (sections,
 *      enrollments, payments, queue tickets, proposals, forecasts, ...), children first, by walking the real
 *      foreign-key graph, so no orphan and no RESTRICT error is left to chance.
 *   4. The test student accounts are deleted the same way, plus their audit rows and personal access tokens.
 *   4b. A real student whose category was derived again since the archive (the classifier judged them against the
 *       rehearsal's next term) gets the category the audit trail says they had before; the flip's audit rows go.
 *   4c. The student-number counter gives back the numbers the deleted accounts took from its top.
 *   4d. Queue cycles opened since the archive that hold no ticket any more are deleted; the cycle the rehearsal's
 *       first claim closed is open again.
 *   5. Audit rows that only describe the deleted things (and the target term's archive) are removed.
 *   6. Invariants: the target term and every earlier term have exactly the same section, enrollment, grade,
 *      payment and proposal counts as before; one slot row points at the target; no later term remains.
 *      Any failure throws and the transaction rolls back.
 *
 * Nothing here touches fee schedules, curricula, rooms, faculty or staff.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Booted only when run directly (php scripts/restore_presentation_term6.php); a test can require this file
// for its functions without re-bootstrapping the application.
$runDirectly = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if ($runDirectly) {
    // From backend/scripts/ it finds backend/; copied elsewhere (for example /tmp in the container), run it with
    // the application folder as the working directory (docker exec -w /app ...).
    if (! is_file(getcwd().'/vendor/autoload.php')) {
        chdir(dirname(__DIR__));
    }
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
}

/** @return array<string, string|bool> */
function pr_opts(array $argv): array
{
    $out = ['apply' => false, 'notifications' => false, 'year' => '2025-2026', 'sem' => '2nd', 'accounts-since' => '2026-09-25', 'max-accounts' => '10', 'max-category-reverts' => '5'];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--apply') {
            $out['apply'] = true;
        } elseif ($arg === '--notifications') {
            $out['notifications'] = true;
        } elseif (preg_match('/^--([a-z-]+)=(.+)$/', $arg, $m) === 1) {
            $out[$m[1]] = $m[2];
        } else {
            fwrite(STDERR, "Unknown argument: {$arg}\n");
            exit(64);
        }
    }

    return $out;
}

function pr_out(string $line = ''): void
{
    echo $line, "\n";
}

final class GuardFailed extends RuntimeException {}

function pr_guard(bool $ok, string $label, string $detail = ''): void
{
    pr_out(sprintf('  [%s] %s%s', $ok ? 'ok' : 'FAIL', $label, $detail === '' ? '' : ' — '.$detail));
    if (! $ok) {
        throw new GuardFailed($label);
    }
}

/** Foreign keys that point AT $parent: child table, child column, parent column, delete rule. */
function pr_childrenOf(string $parent): array
{
    static $cache = [];
    if (isset($cache[$parent])) {
        return $cache[$parent];
    }
    $rows = DB::select(
        'SELECT kcu.TABLE_NAME AS child, kcu.COLUMN_NAME AS child_col, kcu.REFERENCED_COLUMN_NAME AS parent_col, rc.DELETE_RULE AS rule
           FROM information_schema.KEY_COLUMN_USAGE kcu
           JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
             ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.TABLE_NAME = kcu.TABLE_NAME
          WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.REFERENCED_TABLE_NAME = ?
          ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME',
        [$parent],
    );

    return $cache[$parent] = array_map(fn ($r): array => ['child' => $r->child, 'col' => $r->child_col, 'parent_col' => $r->parent_col, 'rule' => strtoupper((string) $r->rule)], $rows);
}

/**
 * Deletes the rows of $table matching $where (bindings $bind) after first deleting, depth first, every child
 * row that references them (CASCADE and RESTRICT children alike; SET NULL children are left to the database).
 *
 * @param  array<string, int>  $report  rows deleted per table
 * @param  array<string, true>  $seen
 */
function pr_purge(string $table, string $where, array $bind, array &$report, array &$seen): void
{
    $signature = $table.'|'.$where.'|'.json_encode($bind);
    if (isset($seen[$signature])) {
        return;
    }
    $seen[$signature] = true;

    foreach (pr_childrenOf($table) as $fk) {
        if ($fk['rule'] === 'SET NULL' || $fk['child'] === $table) {
            continue;
        }
        pr_purge(
            $fk['child'],
            sprintf('`%s` IN (SELECT `%s` FROM `%s` WHERE %s)', $fk['col'], $fk['parent_col'], $table, $where),
            $bind,
            $report,
            $seen,
        );
    }

    $deleted = DB::delete(sprintf('DELETE FROM `%s` WHERE %s', $table, $where), $bind);
    if ($deleted > 0) {
        $report[$table] = ($report[$table] ?? 0) + $deleted;
    }
}

/** @return list<int> */
function pr_ids(string $sql, array $bind = []): array
{
    return array_map(fn ($r): int => (int) $r->id, DB::select($sql, $bind));
}

function pr_placeholders(array $values): string
{
    return implode(',', array_fill(0, max(count($values), 1), '?'));
}

/** Counts that must be identical before and after for the target term and every earlier term. */
function pr_protectedCounts(int $targetId, array $keepTermIds): array
{
    $in = pr_placeholders($keepTermIds);
    $counts = [];
    $counts['sections by term/status'] = array_map(fn ($r): string => "{$r->academic_term_id}/{$r->status}={$r->n}", DB::select("SELECT academic_term_id, status, COUNT(*) n FROM sections WHERE academic_term_id IN ($in) GROUP BY academic_term_id, status ORDER BY 1,2", $keepTermIds));
    $counts['enrollments by term/status'] = array_map(fn ($r): string => "{$r->academic_term_id}/{$r->status}={$r->n}", DB::select("SELECT academic_term_id, status, COUNT(*) n FROM enrollments WHERE academic_term_id IN ($in) GROUP BY academic_term_id, status ORDER BY 1,2", $keepTermIds));
    $counts['grades by term/status'] = array_map(fn ($r): string => "{$r->academic_term_id}/{$r->status}={$r->n}", DB::select("SELECT academic_term_id, status, COUNT(*) n FROM academic_grades WHERE academic_term_id IN ($in) GROUP BY academic_term_id, status ORDER BY 1,2", $keepTermIds));
    $counts['payments of kept terms'] = [(string) DB::selectOne("SELECT COUNT(*) n FROM payments p JOIN enrollments e ON e.id = p.enrollment_id WHERE e.academic_term_id IN ($in)", $keepTermIds)->n];
    $counts['proposals by term/status'] = array_map(fn ($r): string => "{$r->academic_term_id}/{$r->status}/{$r->college}={$r->n}", DB::select("SELECT academic_term_id, status, college, COUNT(*) n FROM schedule_proposals WHERE academic_term_id IN ($in) GROUP BY academic_term_id, status, college ORDER BY 1,2,3", $keepTermIds));
    $counts['windows of target'] = array_map(fn ($r): string => "{$r->audience}={$r->opens_at}..{$r->closes_at}", DB::select('SELECT audience, opens_at, closes_at FROM academic_term_enrollment_windows WHERE academic_term_id = ? ORDER BY audience', [$targetId]));

    return $counts;
}

/**
 * A real student whose category was derived again after the archive (a rehearsal opened the next term, so the
 * classifier judged them against it) goes back to the category the audit trail says they had before: the
 * `before` value of their EARLIEST reclassification since `$since`. `derived_at` goes back to the time of their
 * latest earlier reclassification (NULL if there was none). The audit rows of the flip are collected in `$flipAuditIds`
 * so they can be removed with the other rows that only describe the rehearsal.
 *
 * @param  list<int>  $flipAuditIds
 * @return array<int, array{category: ?string, derived_at: ?string}> student id => what it was put back to
 */
function pr_putBackCategories(string $since, int $max, array &$flipAuditIds): array
{
    $flips = DB::select(
        "SELECT a.id, a.auditable_id, a.before_values, sp.id AS exists_in_sp
           FROM audit_logs a LEFT JOIN student_profiles sp ON sp.id = a.auditable_id
          WHERE a.action = 'student_profile.enrollment_category_reclassified' AND a.auditable_type = 'student_profile' AND a.created_at >= ?
          ORDER BY a.id",
        [$since],
    );
    $reverts = [];
    foreach ($flips as $flip) {
        $flipAuditIds[] = (int) $flip->id;
        $studentId = (int) $flip->auditable_id;
        if ($flip->exists_in_sp !== null && ! isset($reverts[$studentId])) {
            $before = json_decode((string) $flip->before_values, true);
            $reverts[$studentId] = ['category' => is_array($before) ? ($before['enrollment_category'] ?? null) : null, 'derived_at' => null];
        }
    }
    pr_guard(count($reverts) <= $max, 'number of category flips to put back is within the limit', count($reverts).' found (limit '.$max.')');

    foreach ($reverts as $studentId => $revert) {
        $earlier = DB::selectOne(
            "SELECT created_at FROM audit_logs WHERE action = 'student_profile.enrollment_category_reclassified' AND auditable_type = 'student_profile' AND auditable_id = ? AND created_at < ? ORDER BY id DESC LIMIT 1",
            [$studentId, $since],
        );
        $reverts[$studentId]['derived_at'] = $earlier === null ? null : (string) $earlier->created_at;
        // Raw UPDATE: updated_at is deliberately left alone.
        DB::update('UPDATE student_profiles SET enrollment_category = ?, enrollment_category_derived_at = ? WHERE id = ?', [$revert['category'], $reverts[$studentId]['derived_at'], $studentId]);
        pr_out(sprintf('  student #%d: category -> %s, derived_at -> %s', $studentId, $revert['category'] ?? 'NULL', $reverts[$studentId]['derived_at'] ?? 'NULL'));
    }
    if ($reverts === []) {
        pr_out('  (no real student was reclassified since the archive)');
    }

    return $reverts;
}

/**
 * Each year has one counter (`student_number_sequences`). The deleted test accounts took numbers from the TOP of
 * it, so peel those off again: while the counter's value is a number one of the deleted accounts held, step down.
 * A counter whose top number belongs to a real student is never touched.
 *
 * @param  list<object>  $accounts  rows with `student_number`
 * @return array<int, array{from: int, to: int}> year => change
 */
function pr_giveBackStudentNumbers(array $accounts): array
{
    $held = [];
    foreach ($accounts as $account) {
        if ($account->student_number !== null && preg_match('/^(\d{4})-\d{2}-(\d{5})$/', (string) $account->student_number, $m) === 1) {
            $held[(int) $m[1]][(int) $m[2]] = true;
        }
    }
    $changed = [];
    // `year` and `last_value` are quoted: LAST_VALUE is a reserved word in MySQL 8 (production), not in MariaDB.
    foreach (DB::select('SELECT `year`, `last_value` FROM student_number_sequences ORDER BY `year`') as $sequence) {
        $value = (int) $sequence->last_value;
        $from = $value;
        // Only reclaim known deleted accounts at the top. An unused gap is not evidence
        // that the rehearsal took that number; another month may still use its suffix.
        while ($value > 0
            && isset($held[(int) $sequence->year][$value])
            && ! DB::selectOne('SELECT 1 FROM student_profiles WHERE student_number LIKE ? LIMIT 1', [sprintf('%04d-__-%05d', $sequence->year, $value)])) {
            $value--;
        }
        if ($value !== $from) {
            DB::update('UPDATE student_number_sequences SET `last_value` = ?, updated_at = ? WHERE `year` = ?', [$value, date('Y-m-d H:i:s'), $sequence->year]);
            $changed[(int) $sequence->year] = ['from' => $from, 'to' => $value];
            pr_out(sprintf('  %d counter: %d -> %d', $sequence->year, $from, $value));
        }
    }
    if ($changed === []) {
        pr_out('  (no counter was holding a number of a deleted account)');
    }

    return $changed;
}

/**
 * Queue cycles opened since the archive that hold no ticket any more (the purge removed the tickets) are deleted.
 * If that leaves no open cycle, the latest cycle that was open before the archive and closed after it (closed by
 * the first claim of the rehearsal) is opened again. Returns that cycle's id, and adds the deleted ids to the
 * audit clean-up list.
 *
 * @param  array<string, int>  $report
 * @param  array<string, list<int>>  $auditTargets
 */
function pr_cleanQueueCycles(string $since, array &$report, array &$auditTargets): ?int
{
    $emptyIds = pr_ids('SELECT c.id FROM queue_cycles c WHERE c.created_at >= ? AND NOT EXISTS (SELECT 1 FROM queue_tickets t WHERE t.queue_cycle_id = c.id) ORDER BY c.id', [$since]);
    if ($emptyIds !== []) {
        $report['queue_cycles'] = DB::delete('DELETE FROM queue_cycles WHERE id IN ('.pr_placeholders($emptyIds).')', $emptyIds);
        $auditTargets['queue_cycle'] = $emptyIds;
        pr_out('  empty cycles opened since the archive deleted: #'.implode(', #', $emptyIds));
    } else {
        pr_out('  (no empty cycle was opened since the archive)');
    }

    $reopened = null;
    if ((int) DB::selectOne('SELECT COUNT(*) n FROM queue_cycles WHERE closed_at IS NULL')->n === 0) {
        $cycle = DB::selectOne('SELECT id FROM queue_cycles WHERE created_at < ? AND closed_at >= ? ORDER BY id DESC LIMIT 1', [$since, $since]);
        if ($cycle !== null) {
            DB::update('UPDATE queue_cycles SET closed_at = NULL, updated_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $cycle->id]);
            $reopened = (int) $cycle->id;
            pr_out("  cycle #{$reopened} was closed by the rehearsal's first claim -> open again");
        }
    }

    return $reopened;
}

function pr_maskEmail(?string $email): string
{
    if ($email === null || ! str_contains($email, '@')) {
        return '(none)';
    }
    [$local, $domain] = explode('@', $email, 2);

    return substr($local, 0, 2).'***@'.$domain;
}

function pr_run(array $o): int
{
    $apply = $o['apply'] === true;
    pr_out(str_repeat('=', 78));
    pr_out('PRESENTATION RESTORE — '.($apply ? 'APPLY (commits if every guard passes)' : 'DRY RUN (rolls back, writes nothing)'));
    pr_out('database: '.DB::connection()->getDatabaseName().'   now: '.date('Y-m-d H:i:s'));
    pr_out(str_repeat('=', 78));

    DB::beginTransaction();
    try {
        // ---------------------------------------------------------------- 1. guards
        pr_out("\n1. GUARDS");
        $target = DB::selectOne('SELECT * FROM academic_terms WHERE school_year = ? AND semester = ?', [$o['year'], $o['sem']]);
        pr_guard($target !== null, "target term {$o['year']} · {$o['sem']} exists", $target ? "id {$target->id}, status {$target->status}" : '');
        $targetId = (int) $target->id;
        $archivedAt = $target->archived_at;
        // Everything the rehearsal did starts a little before the archive (the Registrar Head acts, then archives);
        // --since overrides it when the first rehearsal action came earlier (read the audit trail first).
        $since = $archivedAt === null ? null : date('Y-m-d H:i:s', strtotime((string) $archivedAt) - 120);
        if (isset($o['since'])) {
            $explicit = strtotime((string) $o['since']);
            pr_guard($explicit !== false, '--since is a valid date and time', (string) $o['since']);
            $since = date('Y-m-d H:i:s', $explicit);
        }
        pr_out('  rehearsal lookback starts at: '.($since ?? '(none: the target term was not archived)'));

        $aheadIds = pr_ids('SELECT id FROM academic_terms WHERE school_year > ? ORDER BY id', [$o['year']]);
        $keepIds = pr_ids('SELECT id FROM academic_terms WHERE school_year <= ? ORDER BY id', [$o['year']]);
        pr_guard(in_array($targetId, $keepIds, true), 'target term is not itself "ahead"');
        pr_out('  later terms to delete: '.($aheadIds === [] ? '(none)' : implode(', ', $aheadIds)));

        $notLocked = (int) DB::selectOne("SELECT COUNT(*) n FROM academic_grades WHERE academic_term_id = ? AND status <> 'locked'", [$targetId])->n;
        $locked = (int) DB::selectOne("SELECT COUNT(*) n FROM academic_grades WHERE academic_term_id = ? AND status = 'locked'", [$targetId])->n;
        pr_guard($locked > 0 && $notLocked === 0, 'every grade of the target term is locked', "{$locked} locked, {$notLocked} not locked");

        $published = (int) DB::selectOne("SELECT COUNT(*) n FROM sections WHERE academic_term_id = ? AND status = 'published'", [$targetId])->n;
        $unpublished = (int) DB::selectOne("SELECT COUNT(*) n FROM sections WHERE academic_term_id = ? AND status <> 'published'", [$targetId])->n;
        pr_guard($published > 0 && $unpublished === 0, 'all sections of the target term are published', "{$published} published, {$unpublished} other");

        $colleges = DB::select("SELECT college FROM schedule_proposals WHERE academic_term_id = ? AND status = 'published'", [$targetId]);
        $have = array_map(fn ($r) => strtolower((string) $r->college), $colleges);
        $missing = array_values(array_diff(['ccs', 'coe', 'coa', 'cbae'], $have));
        pr_guard($missing === [], 'CCS, COE, COA and CBAE each have a published schedule', $missing === [] ? '' : 'missing: '.implode(',', $missing));

        $windows = DB::select('SELECT audience, closes_at FROM academic_term_enrollment_windows WHERE academic_term_id = ?', [$targetId]);
        $stillOpen = array_filter($windows, fn ($w) => $w->closes_at === null || strtotime((string) $w->closes_at) > time());
        $mayJune = array_filter($windows, fn ($w) => $w->closes_at !== null && in_array((int) date('n', strtotime((string) $w->closes_at)), [5, 6], true));
        pr_guard($windows !== [] && $stillOpen === [], 'every enrollment window of the target term has closed', count($windows).' windows');
        pr_guard(count($mayJune) === count($windows), 'every enrollment window closed in May or June', implode(', ', array_unique(array_map(fn ($w) => substr((string) $w->closes_at, 0, 10), $windows))));

        $enrolled = (int) DB::selectOne("SELECT COUNT(*) n FROM enrollments WHERE academic_term_id = ? AND status = 'enrolled'", [$targetId])->n;
        pr_guard($enrolled > 0, 'the target term has enrolled students', "{$enrolled} enrolled");

        // test accounts
        $accounts = DB::select(
            "SELECT u.id, u.role, u.email, u.created_at, sp.id AS student_id, sp.student_number
               FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id
              WHERE u.role = 'student' AND u.created_at >= ? ORDER BY u.id",
            [$o['accounts-since']],
        );
        pr_guard(count($accounts) <= (int) $o['max-accounts'], 'number of test student accounts is within the limit', count($accounts).' found (limit '.$o['max-accounts'].')');
        foreach ($accounts as $a) {
            pr_out(sprintf('    account user #%d  %s  number %s  created %s', $a->id, pr_maskEmail($a->email), $a->student_number ?? '(no profile)', $a->created_at));
        }
        $accountUserIds = array_map(fn ($a): int => (int) $a->id, $accounts);
        $accountStudentIds = array_values(array_filter(array_map(fn ($a): ?int => $a->student_id === null ? null : (int) $a->student_id, $accounts)));
        if ($accountStudentIds !== []) {
            $history = (int) DB::selectOne(
                'SELECT COUNT(*) n FROM enrollments WHERE student_id IN ('.pr_placeholders($accountStudentIds).') AND academic_term_id IN ('.pr_placeholders($keepIds).')',
                [...$accountStudentIds, ...$keepIds],
            )->n;
            pr_guard($history === 0, 'the test accounts have no enrollment in the target term or earlier', "{$history} found");
        }

        // ---------------------------------------------------------------- before counts
        $before = pr_protectedCounts($targetId, $keepIds);
        $usersBefore = (int) DB::selectOne('SELECT COUNT(*) n FROM users')->n;
        $studentsBefore = (int) DB::selectOne('SELECT COUNT(*) n FROM student_profiles')->n;
        $slotBefore = DB::select('SELECT id, academic_term_id FROM academic_term_current_slots');

        // ---------------------------------------------------------------- collect ids for the audit clean-up
        $auditTargets = ['academic_term' => $aheadIds];
        if ($aheadIds !== []) {
            $auditTargets['section'] = pr_ids('SELECT id FROM sections WHERE academic_term_id IN ('.pr_placeholders($aheadIds).')', $aheadIds);
            $auditTargets['schedule_proposal'] = pr_ids('SELECT id FROM schedule_proposals WHERE academic_term_id IN ('.pr_placeholders($aheadIds).')', $aheadIds);
            $auditTargets['enrollment'] = pr_ids('SELECT id FROM enrollments WHERE academic_term_id IN ('.pr_placeholders($aheadIds).')', $aheadIds);
        }
        $auditTargets['student_profile'] = $accountStudentIds;
        $auditTargets['user_account'] = $accountUserIds;
        if ($accountStudentIds !== []) {
            $auditTargets['enrollment'] = array_values(array_unique([...($auditTargets['enrollment'] ?? []), ...pr_ids('SELECT id FROM enrollments WHERE student_id IN ('.pr_placeholders($accountStudentIds).')', $accountStudentIds)]));
        }
        $enrollmentIds = $auditTargets['enrollment'] ?? [];
        if ($enrollmentIds !== []) {
            $auditTargets['queue_ticket'] = pr_ids('SELECT id FROM queue_tickets WHERE enrollment_id IN ('.pr_placeholders($enrollmentIds).')', $enrollmentIds);
            $auditTargets['assessment'] = pr_ids('SELECT id FROM assessments WHERE enrollment_id IN ('.pr_placeholders($enrollmentIds).')', $enrollmentIds);
        }

        // ---------------------------------------------------------------- 2. restore the target term
        pr_out("\n2. RESTORE THE TARGET TERM");
        $updated = DB::update(
            "UPDATE academic_terms SET status = 'semester_ongoing', closed_at = NULL, archived_at = NULL, updated_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $targetId],
        );
        pr_out("  term #{$targetId}: {$target->status} (archived_at ".($archivedAt ?? 'NULL').") -> semester_ongoing, closed_at/archived_at NULL  [{$updated} row]");
        $slotRows = (int) DB::selectOne('SELECT COUNT(*) n FROM academic_term_current_slots')->n;
        if ($slotRows === 0) {
            DB::insert('INSERT INTO academic_term_current_slots (id, academic_term_id, created_at, updated_at) VALUES (1, ?, NOW(), NOW())', [$targetId]);
            pr_out("  current-term slot created -> term #{$targetId}");
        } else {
            DB::update('UPDATE academic_term_current_slots SET academic_term_id = ?, updated_at = ?', [$targetId, date('Y-m-d H:i:s')]);
            pr_out("  current-term slot -> term #{$targetId} (was ".implode(', ', array_map(fn ($s) => '#'.($s->academic_term_id ?? 'NULL'), $slotBefore)).')');
        }

        // ---------------------------------------------------------------- 3 + 4. purge
        pr_out("\n3. PURGE EVERYTHING AHEAD OF THE TARGET TERM");
        $report = [];
        $seen = [];
        if ($aheadIds !== []) {
            pr_purge('academic_terms', 'id IN ('.implode(',', array_map('intval', $aheadIds)).')', [], $report, $seen);
        }
        pr_out("\n4. DELETE THE TEST STUDENT ACCOUNTS");
        if ($accountUserIds !== []) {
            $userList = implode(',', array_map('intval', $accountUserIds));
            $tokens = DB::delete("DELETE FROM personal_access_tokens WHERE tokenable_type = 'App\\\\Models\\\\User' AND tokenable_id IN ({$userList})");
            if ($tokens > 0) {
                $report['personal_access_tokens'] = $tokens;
            }
            pr_purge('users', "id IN ({$userList})", [], $report, $seen);
        } else {
            pr_out('  (no test accounts)');
        }

        // ---------------------------------------------------------------- 4b-4d. what the rehearsal changed on rows that stay
        $flipAuditIds = [];
        $reopenedCycleId = null;
        pr_out("\n4b. PUT BACK THE CATEGORIES THE REHEARSAL FLIPPED ON REAL STUDENTS");
        if ($since === null) {
            pr_out('  (the target term was not archived: nothing to put back)');
        } else {
            pr_putBackCategories($since, (int) $o['max-category-reverts'], $flipAuditIds);
        }
        pr_out("\n4c. GIVE BACK THE STUDENT NUMBERS THE TEST ACCOUNTS TOOK");
        pr_giveBackStudentNumbers($accounts);
        pr_out("\n4d. QUEUE CYCLES THE REHEARSAL OPENED OR CLOSED");
        if ($since === null) {
            pr_out('  (the target term was not archived: nothing to clean)');
        } else {
            $reopenedCycleId = pr_cleanQueueCycles($since, $report, $auditTargets);
        }

        // ---------------------------------------------------------------- 5. audit clean-up
        pr_out("\n5. AUDIT ROWS THAT ONLY DESCRIBE THE DELETED THINGS");
        $auditDeleted = 0;
        foreach (array_chunk($flipAuditIds, 500) as $chunk) {
            $auditDeleted += DB::delete('DELETE FROM audit_logs WHERE id IN ('.pr_placeholders($chunk).')', $chunk);
        }
        if ($reopenedCycleId !== null && $since !== null) {
            $auditDeleted += DB::delete(
                "DELETE FROM audit_logs WHERE auditable_type = 'queue_cycle' AND auditable_id = ? AND action = 'queue_cycle.closed' AND created_at >= ?",
                [$reopenedCycleId, $since],
            );
        }
        foreach ($auditTargets as $type => $idList) {
            if ($idList === []) {
                continue;
            }
            foreach (array_chunk($idList, 500) as $chunk) {
                $auditDeleted += DB::delete('DELETE FROM audit_logs WHERE auditable_type = ? AND auditable_id IN ('.pr_placeholders($chunk).')', [$type, ...$chunk]);
            }
        }
        if ($archivedAt !== null) {
            $auditDeleted += DB::delete(
                "DELETE FROM audit_logs WHERE auditable_type = 'academic_term' AND auditable_id = ? AND action IN ('academic_term.closed', 'academic_term.archived') AND created_at >= ?",
                [$targetId, date('Y-m-d H:i:s', strtotime((string) $archivedAt) - 120)],
            );
        }
        if ($auditDeleted > 0) {
            $report['audit_logs (by entity)'] = $auditDeleted;
        }
        if ($o['notifications'] === true && $since !== null) {
            $n = DB::delete('DELETE FROM notifications WHERE created_at >= ?', [$since]);
            if ($n > 0) {
                $report['notifications (since archive)'] = $n;
            }
        } elseif ($since !== null) {
            $n = (int) DB::selectOne('SELECT COUNT(*) n FROM notifications WHERE created_at >= ?', [$since])->n;
            pr_out("  (notifications created since the archive: {$n}; kept. Pass --notifications to delete them.)");
        }

        ksort($report);
        pr_out("\n  ROWS DELETED PER TABLE");
        foreach ($report as $table => $n) {
            pr_out(sprintf('    %-42s %7d', $table, $n));
        }
        if ($report === []) {
            pr_out('    (nothing)');
        }

        // ---------------------------------------------------------------- 6. invariants
        pr_out("\n6. INVARIANTS");
        $after = pr_protectedCounts($targetId, $keepIds);
        foreach ($before as $label => $values) {
            pr_guard($values === $after[$label], "unchanged: {$label}", count($values) === 1 ? (string) $values[0] : count($values).' groups');
        }
        $slots = DB::select('SELECT academic_term_id FROM academic_term_current_slots');
        pr_guard(count($slots) === 1 && (int) $slots[0]->academic_term_id === $targetId, 'exactly one current-term slot, pointing at the target term');
        $termNow = DB::selectOne('SELECT status, closed_at, archived_at FROM academic_terms WHERE id = ?', [$targetId]);
        pr_guard($termNow->status === 'semester_ongoing' && $termNow->closed_at === null && $termNow->archived_at === null, 'target term is semester_ongoing and not archived');
        pr_guard((int) DB::selectOne('SELECT COUNT(*) n FROM academic_terms WHERE school_year > ?', [$o['year']])->n === 0, 'no later term remains');
        $usersAfter = (int) DB::selectOne('SELECT COUNT(*) n FROM users')->n;
        $studentsAfter = (int) DB::selectOne('SELECT COUNT(*) n FROM student_profiles')->n;
        pr_guard($usersBefore - $usersAfter === count($accountUserIds), 'only the test accounts were removed from users', "{$usersBefore} -> {$usersAfter}");
        pr_guard($studentsBefore - $studentsAfter === count($accountStudentIds), 'only the test accounts were removed from student_profiles', "{$studentsBefore} -> {$studentsAfter}");
        if ($since !== null) {
            $emptyCyclesLeft = (int) DB::selectOne('SELECT COUNT(*) n FROM queue_cycles c WHERE c.created_at >= ? AND NOT EXISTS (SELECT 1 FROM queue_tickets t WHERE t.queue_cycle_id = c.id)', [$since])->n;
            pr_guard($emptyCyclesLeft === 0, 'no empty queue cycle opened since the archive remains', "{$emptyCyclesLeft} left");
            $flipsLeft = (int) DB::selectOne("SELECT COUNT(*) n FROM audit_logs WHERE action = 'student_profile.enrollment_category_reclassified' AND created_at >= ?", [$since])->n;
            pr_guard($flipsLeft === 0, 'no category flip since the archive remains in the audit trail', "{$flipsLeft} left");
        }
        $leftTickets = (int) DB::selectOne('SELECT COUNT(*) n FROM queue_tickets')->n;
        pr_out("  info: queue tickets left: {$leftTickets}; payments left: ".DB::selectOne('SELECT COUNT(*) n FROM payments')->n);

        // ---------------------------------------------------------------- result
        pr_out('');
        if ($apply) {
            DB::commit();
            pr_out('RESULT: COMMITTED.');
        } else {
            DB::rollBack();
            pr_out('RESULT: DRY RUN — everything above was ROLLED BACK. Nothing was written. Re-run with --apply to commit.');
        }

        return 0;
    } catch (GuardFailed $e) {
        DB::rollBack();
        pr_out("\nRESULT: ABORTED by guard \"{$e->getMessage()}\". Everything was rolled back; nothing was written.");

        return 2;
    } catch (Throwable $e) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        pr_out("\nRESULT: ERROR, rolled back, nothing was written: ".get_class($e).': '.substr($e->getMessage(), 0, 400));

        return 1;
    }
}

if ($runDirectly) {
    exit(pr_run(pr_opts($argv)));
}
