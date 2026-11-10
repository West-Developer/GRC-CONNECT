<?php

namespace App\Actions\Organization;

use App\Domain\Audit\AuditRequestContext;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closes one enrollment cycle and opens the next in a single transaction.
 *
 * This is the only way a Registrar Head creates a term once one exists.
 * Term creation is the *last* step of a semester rather than a standing
 * form, so the school year and semester are always chosen with the term
 * being retired in view, and the system can never sit with no current term
 * because someone archived and forgot to create the successor.
 *
 * Composes the two existing actions rather than reimplementing them:
 * `TransitionAcademicTerm` still owns archiving (row lock, closed_at /
 * archived_at ordering, clearing the current slot, the archive audit
 * entry), and `CreateAcademicTerm` still owns creation (single-current-slot
 * enforcement, the four college workflows, the five enrollment-window rows,
 * the creation audit entry). Wrapping both in one transaction means a
 * failure in either leaves the old term un-archived and no new term
 * created.
 *
 * The new term carries no dates: the Registrar sets the enrollment window
 * afterwards, where the 8:00 AM convention and the per-audience schedule
 * already live.
 */
final class ArchiveAndCreateNextTerm
{
    public function __construct(
        private readonly TransitionAcademicTerm $transition,
        private readonly CreateAcademicTerm $create,
    ) {}

    /**
     * @param  array{school_year: string, semester: string}  $next
     * @return array{0: AcademicTerm, 1: AcademicTerm} [archivedTerm, createdTerm]
     */
    public function execute(
        AcademicTerm $current,
        array $next,
        User $actor,
        AuditRequestContext $context,
    ): array {
        return DB::transaction(function () use ($current, $next, $actor, $context): array {
            $archived = $this->transition->execute($current, 'archive', $actor, $context);

            $created = $this->create->execute($actor, [
                'school_year' => $next['school_year'],
                'semester' => $next['semester'],
                'enrollment_opens_at' => null,
                'enrollment_closes_at' => null,
                'add_drop_deadline_at' => null,
            ], $context);

            return [$archived, $created];
        });
    }
}
