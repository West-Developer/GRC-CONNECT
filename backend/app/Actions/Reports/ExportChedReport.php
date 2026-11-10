<?php

namespace App\Actions\Reports;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Reports\ChedReport;
use App\Domain\Reports\ChedReportFile;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Reports\ChedCsvWriter;
use App\Support\Reports\ChedReportHeader;
use App\Support\Reports\ChedWorkbookWriter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Produces the CSV or Excel file of a CHED / NSTP report for the Registrar Head (ADR 0049) and
 * audits the download (report, term, format and how many rows; never a student). The file is built
 * in the temp folder and the caller sends it and deletes it.
 */
final class ExportChedReport
{
    public const CSV = 'csv';

    public const XLSX = 'xlsx';

    public function __construct(
        private readonly BuildChedReport $build,
        private readonly ChedCsvWriter $csv,
        private readonly ChedWorkbookWriter $workbook,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function execute(ChedReport $report, AcademicTerm $term, string $format, User $actor, AuditRequestContext $context): ChedReportFile
    {
        if (! in_array($format, [self::CSV, self::XLSX], true)) {
            throw new InvalidArgumentException("Unknown export format {$format}.");
        }

        $path = tempnam(sys_get_temp_dir(), 'ched-');

        if ($path === false) {
            throw new RuntimeException('A temporary file for the report could not be created.');
        }

        try {
            $rows = $this->fill($report, $term, $format, $path);

            $this->auditRecorder->record(
                $actor,
                AuditAction::CHED_REPORT_EXPORTED,
                AuditableType::CHED_REPORT,
                $term->id,
                null,
                ['report' => $report->value, 'academic_term_id' => $term->id, 'format' => $format, 'rows' => $rows],
                null,
                $context,
            );
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return new ChedReportFile(
            $path,
            $this->fileName($report, $term, $format),
            $format === self::CSV ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $rows,
        );
    }

    /**
     * @return int the number of student rows written (the summary counts as one)
     */
    private function fill(ChedReport $report, AcademicTerm $term, string $format, string $path): int
    {
        if ($report === ChedReport::NstpSummary) {
            $summary = $this->build->summary($term);

            if ($format === self::CSV) {
                $this->csv->writeSummary($summary, $path);
            } else {
                $this->workbook->writeSummary($summary, $path);
            }

            return 1;
        }

        $rows = $this->build->execute($report, $term)->rows;

        if ($format === self::CSV) {
            $this->csv->writeList($report, $rows, $path);
        } else {
            $this->workbook->writeList($report, $rows, ChedReportHeader::forTerm($term), $path);
        }

        return count($rows);
    }

    private function fileName(ChedReport $report, AcademicTerm $term, string $format): string
    {
        $stem = match ($report) {
            ChedReport::EnrollmentList => 'CHED-Enrollment-List',
            ChedReport::NstpEnrollment => str_replace(' ', '', ChedReport::nstpSubjectOf($term->semester)).'-Form-2B-Enrollment',
            ChedReport::NstpGraduates => 'NSTP-Form-2B-Graduates',
            ChedReport::NstpSummary => 'NSTP-Form-2A-Summary',
        };

        return "{$stem}_{$term->school_year}-{$term->semester}.{$format}";
    }
}
