<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Reports\ExportChedReport;
use App\Actions\Reports\PreviewChedReport;
use App\Domain\Reports\ChedReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\ExportChedReportRequest;
use App\Http\Requests\Api\V1\Reports\ShowChedReportRequest;
use App\Http\Resources\Api\V1\ChedReportRowResource;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use App\Support\Reports\ChedReportHeader;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * The Registrar Head's CHED / NSTP reports (ADR 0049): a preview of each of the four forms for a term,
 * and its download as CSV or as the CHED template filled in (Excel). Registrar Head only.
 */
final class ChedReportController extends Controller
{
    public function show(
        ShowChedReportRequest $request,
        ChedReport $report,
        PreviewChedReport $preview,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $this->authorize('view-ched-reports');

        $term = AcademicTerm::query()->findOrFail($request->academicTermId());
        $actor = $this->actor($request);
        $context = $contextFactory->fromRequest($request);

        if (! $report->isList()) {
            $summary = $preview->summary($term, $actor, $context);

            return $this->noStore(response()->json([
                'data' => $summary,
                'report' => $this->describe($report, $term, $summary['missing_gender'], 0),
            ]));
        }

        $page = (int) $request->validated('page', 1);
        $perPage = (int) $request->validated('per_page', 25);
        $rows = $preview->list($report, $term, $page, $perPage, $request->searchTerm(), $actor, $context);

        return $this->noStore(response()->json([
            'data' => ChedReportRowResource::collection($rows->rows),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($rows->total / $perPage)),
                'per_page' => $perPage,
                'total' => $rows->total,
            ],
            'report' => $this->describe($report, $term, $rows->missingGender, $rows->demoPopulated),
        ]));
    }

    public function export(
        ExportChedReportRequest $request,
        ChedReport $report,
        ExportChedReport $export,
        AuditRequestContextFactory $contextFactory,
    ): BinaryFileResponse {
        $this->authorize('view-ched-reports');

        $term = AcademicTerm::query()->findOrFail($request->academicTermId());
        $file = $export->execute(
            $report,
            $term,
            (string) $request->validated('format'),
            $this->actor($request),
            $contextFactory->fromRequest($request),
        );

        // Built directly, not with `response()->download()`: that helper marks the response `public`,
        // and a named student list must never be cacheable by a shared proxy.
        $response = new BinaryFileResponse($file->path, 200, [
            'Content-Type' => $file->contentType,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ], false);

        return $response
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->downloadName)
            ->deleteFileAfterSend(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(ChedReport $report, AcademicTerm $term, int $missingGender, int $demoPopulated): array
    {
        $header = ChedReportHeader::forTerm($term);

        return [
            'key' => $report->value,
            'title' => $report->titleFor($term->semester),
            'term_label' => $header->termLabel,
            'school_year' => $term->school_year,
            'columns' => $report->columns(),
            'missing_gender' => $missingGender,
            'demo_populated' => $demoPopulated,
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
