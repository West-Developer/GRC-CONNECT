<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\BuildCorSnapshot;
use App\Actions\Enrollment\ListEnrollmentDocuments;
use App\Domain\Enrollment\CorDisplay;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EnrollmentDocument\IndexEnrollmentDocumentRequest;
use App\Http\Requests\Api\V1\EnrollmentDocument\ShowEnrollmentDocumentRequest;
use App\Http\Resources\Api\V1\EnrollmentDocumentDetailResource;
use App\Http\Resources\Api\V1\EnrollmentDocumentResource;
use App\Models\EnrollmentDocument;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class EnrollmentDocumentController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(IndexEnrollmentDocumentRequest $request, ListEnrollmentDocuments $listEnrollmentDocuments): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('viewAny', EnrollmentDocument::class);

        $documents = $listEnrollmentDocuments->execute($actor, $request->validated());

        $response = EnrollmentDocumentResource::collection($documents)->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function show(
        ShowEnrollmentDocumentRequest $request,
        EnrollmentDocument $enrollmentDocument,
        BuildCorSnapshot $buildCorSnapshot,
    ): JsonResponse {
        $this->authenticatedUser($request);
        $this->authorize('view', $enrollmentDocument);

        $this->hydrateLegacyCorSnapshot($enrollmentDocument, $buildCorSnapshot);

        return $this->cachePrivateResponse(
            EnrollmentDocumentDetailResource::make($enrollmentDocument)->response($request),
        );
    }

    /**
     * @throws AuthenticationException
     */
    public function downloadPdf(
        Request $request,
        EnrollmentDocument $enrollmentDocument,
        BuildCorSnapshot $buildCorSnapshot,
    ): Response {
        $this->authenticatedUser($request);
        $this->authorize('view', $enrollmentDocument);

        $this->hydrateLegacyCorSnapshot($enrollmentDocument, $buildCorSnapshot);

        $pdf = Pdf::loadView('pdf.certificate-of-registration', [
            'document' => $enrollmentDocument,
            'snapshot' => $enrollmentDocument->snapshot,
        ])->setPaper('a4', 'portrait');

        $fileName = CorDisplay::downloadFilename(
            $enrollmentDocument->snapshot['student']['name'] ?? 'Student',
            $enrollmentDocument->generated_at,
        );

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @throws AuthenticationException
     */
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * The conversion migration may be temporarily unavailable on an existing
     * database. Every enrolled legacy record remains a student record even
     * when an old import has no payment row, so render a transient COR
     * snapshot for this protected response rather than making the student
     * wait for schema privileges. It is never saved: the `cor:backfill`
     * command persists the immutable snapshot once migrated.
     */
    private function hydrateLegacyCorSnapshot(
        EnrollmentDocument $document,
        BuildCorSnapshot $buildCorSnapshot,
    ): void {
        $document->loadMissing([
            'enrollment.student.user',
            'enrollment.student.program',
            'enrollment.academicTerm',
            'enrollment.enrollmentSubjects.section.subject',
            'enrollment.assessment.items',
            'enrollment.payment.confirmer',
        ]);

        if ($document->document_type === EnrollmentDocumentType::Cor && $document->enrollment !== null) {
            $freshSnapshot = $buildCorSnapshot->execute($document->enrollment, $document->enrollment->payment);
            $document->setAttribute('snapshot', $freshSnapshot);

            if ($document->exists && $document->content_hash !== $buildCorSnapshot->hash($freshSnapshot)) {
                $hash = $buildCorSnapshot->hash($freshSnapshot);
                $document->snapshot = $freshSnapshot;
                $document->content_hash = $hash;
                // Written with a plain UPDATE that names `generated_at`: under
                // MariaDB's legacy `explicit_defaults_for_timestamp = 0` that
                // column has an implicit ON UPDATE CURRENT_TIMESTAMP, and a
                // model save would stamp the DB clock over the real issue time
                // every time a COR is re-synced (the +8h drift).
                DB::table($document->getTable())->where('id', $document->id)->update([
                    'snapshot' => json_encode($freshSnapshot, JSON_THROW_ON_ERROR),
                    'content_hash' => $hash,
                    'generated_at' => DB::raw('`generated_at`'),
                ]);
                $document->syncOriginal();
            }

            return;
        }

        if ($document->snapshot !== null) {
            return;
        }

        if ($document->enrollment !== null) {
            $document->setAttribute(
                'snapshot',
                $buildCorSnapshot->execute($document->enrollment, $document->enrollment->payment),
            );
        }
    }

    /**
     * `private`: a generated COR is one student's own academic
     * record — no shared cache may retain any response from this endpoint.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
