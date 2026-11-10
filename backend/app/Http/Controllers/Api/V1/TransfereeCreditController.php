<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Academic\CreateTransfereeCredit;
use App\Actions\Academic\DeleteTransfereeCreditDraft;
use App\Actions\Academic\ListTransfereeCredits;
use App\Actions\Academic\SubmitTransfereeCredits;
use App\Actions\Academic\SuggestCreditSubjects;
use App\Actions\Academic\UpdateTransfereeCredit;
use App\Actions\Enrollment\SendQueueLinks;
use App\Domain\Enrollment\QueueLinkOutbox;
use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TransfereeCredit\IndexTransfereeCreditRequest;
use App\Http\Requests\Api\V1\TransfereeCredit\StoreTransfereeCreditRequest;
use App\Http\Requests\Api\V1\TransfereeCredit\UpdateTransfereeCreditRequest;
use App\Http\Resources\Api\V1\CreditSubjectSuggestionResource;
use App\Http\Resources\Api\V1\TransfereeCreditResource;
use App\Models\TransfereeCredit;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TransfereeCreditController extends Controller
{
    /**
     * Which Policy ability governs a PATCH, resolved from the request's
     * `action` field. The Registrar Head decides (`approve`, `reject`,
     * `return`); anything else — a content edit, `endorse`, `decline` — is the
     * Program Chair's `update` (or, for a Student, `editDraft`). Same shape as
     * `AcademicGradeController::ABILITY_FOR_ACTION`.
     *
     * @var array<string, string>
     */
    private const ABILITY_FOR_ACTION = [
        'approve' => 'decide',
        'reject' => 'decide',
        'return' => 'decide',
    ];

    /**
     * @throws AuthenticationException
     */
    public function index(IndexTransfereeCreditRequest $request, ListTransfereeCredits $listTransfereeCredits): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('viewAny', TransfereeCredit::class);

        $credits = $listTransfereeCredits->execute($actor, $request->validated());

        $response = TransfereeCreditResource::collection($credits)->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * A request that repeats a still-open one returns that row (200) rather
     * than a duplicate; a new one is 201.
     *
     * @throws AuthenticationException
     */
    public function store(
        StoreTransfereeCreditRequest $request,
        CreateTransfereeCredit $creator,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('create', TransfereeCredit::class);

        $credit = $creator->execute($request->validated(), $actor, $contextFactory->fromRequest($request));

        $response = TransfereeCreditResource::make($credit)->response($request);
        $response->setStatusCode($credit->wasRecentlyCreated ? 201 : 200);

        return $this->cachePrivateResponse($response);
    }

    /**
     * A Student sends every credit they have saved to the Program Chair. A
     * repeat finds nothing to send and answers 200 with an empty list.
     *
     * @throws AuthenticationException
     */
    public function submit(
        Request $request,
        SubmitTransfereeCredits $submitter,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('submit', TransfereeCredit::class);

        $credits = $submitter->execute($actor, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(TransfereeCreditResource::collection($credits)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateTransfereeCreditRequest $request,
        TransfereeCredit $transfereeCredit,
        UpdateTransfereeCredit $updater,
        SendQueueLinks $sendQueueLinks,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);

        $action = $request->validated('action');
        $ability = is_string($action) && isset(self::ABILITY_FOR_ACTION[$action])
            ? self::ABILITY_FOR_ACTION[$action]
            : ($actor->role === UserRole::Student ? 'editDraft' : 'update');

        $transfereeCredit->loadMissing('student.program');
        $this->authorize($ability, $ability === 'decide' ? TransfereeCredit::class : $transfereeCredit);

        $context = $contextFactory->fromRequest($request);
        $outbox = new QueueLinkOutbox;
        $credit = $updater->execute($transfereeCredit, $request->validated(), $actor, $context, $outbox);

        // After commit: a decision that closed the student's last open row issued their queue number.
        $sendQueueLinks->handle($outbox, $actor, $context);

        return $this->cachePrivateResponse(TransfereeCreditResource::make($credit)->response($request));
    }

    /**
     * A Student removes a subject from their own saved list. Only a draft can
     * be removed.
     *
     * @throws AuthenticationException
     */
    public function destroy(
        Request $request,
        TransfereeCredit $transfereeCredit,
        DeleteTransfereeCreditDraft $deleter,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('deleteDraft', $transfereeCredit);

        $deleter->execute($transfereeCredit, $actor, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(new JsonResponse(null, 204));
    }

    /**
     * Which subjects of the student's curriculum this credit could be mapped
     * to, best first. Advice for the Program Chair, computed on demand and
     * never stored.
     *
     * @throws AuthenticationException
     */
    public function suggestions(
        Request $request,
        TransfereeCredit $transfereeCredit,
        SuggestCreditSubjects $suggester,
    ): JsonResponse {
        $this->authenticatedUser($request);
        $transfereeCredit->loadMissing('student.program');
        $this->authorize('update', $transfereeCredit);

        $response = CreditSubjectSuggestionResource::collection(
            $suggester->execute($transfereeCredit),
        )->response($request);

        return $this->cachePrivateResponse($response);
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
     * `private`: transferee credits carry an academic record — no shared
     * cache may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
