<?php

namespace App\Actions\Reports;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Reports\ChedReport;
use App\Domain\Reports\ChedReportRows;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Support\Audit\AuditRecorder;

/**
 * The Registrar Head's on-screen view of a CHED / NSTP report (ADR 0049). Reading a named student
 * list is a privileged read, so the first page of a report is audited (the later pages of the same
 * look are not, so paging does not flood the log).
 */
final class PreviewChedReport
{
    public function __construct(
        private readonly BuildChedReport $build,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function list(
        ChedReport $report,
        AcademicTerm $term,
        int $page,
        int $perPage,
        ?string $search,
        User $actor,
        AuditRequestContext $context,
    ): ChedReportRows {
        $rows = $this->build->execute($report, $term, $page, $perPage, $search);

        if ($page === 1) {
            $this->audit($report, $term, $rows->total, $search !== null, $actor, $context);
        }

        return $rows;
    }

    /**
     * @return array{
     *     campus: string, classification: string, academic_year: string, semester: string,
     *     enrollment: array<string, array<string, array{m: int, f: int}>>,
     *     graduates: array<string, array{m: int, f: int}>,
     *     missing_gender: int,
     * }
     */
    public function summary(AcademicTerm $term, User $actor, AuditRequestContext $context): array
    {
        $summary = $this->build->summary($term);
        $this->audit(ChedReport::NstpSummary, $term, 1, false, $actor, $context);

        return $summary;
    }

    /** `$searched` says a search narrowed the list; what was typed is never written to the audit log. */
    private function audit(ChedReport $report, AcademicTerm $term, int $count, bool $searched, User $actor, AuditRequestContext $context): void
    {
        $this->auditRecorder->record(
            $actor,
            AuditAction::CHED_REPORT_VIEWED,
            AuditableType::CHED_REPORT,
            $term->id,
            null,
            ['report' => $report->value, 'academic_term_id' => $term->id, 'result_count' => $count, 'searched' => $searched],
            null,
            $context,
        );
    }
}
