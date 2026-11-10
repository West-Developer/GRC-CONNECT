<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Academic\DeleteTorDocument;
use App\Actions\Academic\ListTorDocuments;
use App\Actions\Academic\UploadTorDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TorDocument\IndexTorDocumentRequest;
use App\Http\Requests\Api\V1\TorDocument\StoreTorDocumentRequest;
use App\Http\Resources\Api\V1\TorDocumentResource;
use App\Models\StudentTorDocument;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploaded Transcripts of Records for credit mapping (ADR 0026). Role-level
 * access and the row scope are `StudentTorDocumentPolicy` and
 * `StudentTorDocument::scopeVisibleTo`; the file bytes are served only by
 * `file()`, behind the same authorization, never from a public URL.
 */
final class TorDocumentController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(IndexTorDocumentRequest $request, ListTorDocuments $list): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('viewAny', StudentTorDocument::class);

        $response = TorDocumentResource::collection($list->execute($actor, $request->validated()))->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreTorDocumentRequest $request,
        UploadTorDocument $uploader,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('create', StudentTorDocument::class);

        $document = $uploader->execute($actor, $request->file('file'), $contextFactory->fromRequest($request));

        $response = TorDocumentResource::make($document)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * The file itself, inline so a browser can open a PDF or image.
     *
     * @throws AuthenticationException
     */
    public function file(Request $request, StudentTorDocument $torDocument): StreamedResponse
    {
        $this->authenticatedUser($request);
        $this->authorize('view', $torDocument);

        abort_unless(Storage::disk('local')->exists($torDocument->stored_path), 404, 'This TOR file is no longer available.');

        return Storage::disk('local')->response(
            $torDocument->stored_path,
            $torDocument->original_name,
            [
                'Content-Type' => $torDocument->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    /**
     * @throws AuthenticationException
     */
    public function destroy(
        Request $request,
        StudentTorDocument $torDocument,
        DeleteTorDocument $deleter,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('delete', $torDocument);

        $deleter->execute($actor, $torDocument, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(new JsonResponse(null, 204));
    }

    /**
     * `private`: a Transcript of Records is personal academic data — no shared
     * cache may retain a response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
