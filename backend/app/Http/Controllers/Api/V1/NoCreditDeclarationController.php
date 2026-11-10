<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Academic\ConfirmNoCredit;
use App\Actions\Academic\DeclareNoCredit;
use App\Actions\Enrollment\SendQueueLinks;
use App\Domain\Enrollment\QueueLinkOutbox;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NoCreditDeclarationResource;
use App\Models\NoCreditDeclaration;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "No subject to credit": a Transferee or Returnee with nothing to credit says so, and the Program Chair of
 * their college confirms it so they can enroll (ADR 0026 / 0050, amended). Policy-gated, no `role:` middleware,
 * the same shape as `TransfereeCreditController`.
 */
final class NoCreditDeclarationController extends Controller
{
    /**
     * The signed-in student's own declaration, or `data: null` when they have made none.
     *
     * @throws AuthenticationException
     */
    public function show(Request $request): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('declare', NoCreditDeclaration::class);

        $declaration = NoCreditDeclaration::query()
            ->whereIn('student_id', StudentProfile::query()->where('user_id', $actor->id)->select('id'))
            ->with('student.user')
            ->first();

        $response = $declaration === null
            ? response()->json(['data' => null])
            : NoCreditDeclarationResource::make($declaration)->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * A repeat returns the same declaration (200); the first one is 201.
     *
     * @throws AuthenticationException
     */
    public function store(Request $request, DeclareNoCredit $declarer, AuditRequestContextFactory $contextFactory): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('declare', NoCreditDeclaration::class);

        $declaration = $declarer->execute($actor, $contextFactory->fromRequest($request));

        $response = NoCreditDeclarationResource::make($declaration)->response($request);
        $response->setStatusCode($declaration->wasRecentlyCreated ? 201 : 200);

        return $this->cachePrivateResponse($response);
    }

    /**
     * The declarations still waiting for the signed-in Program Chair, oldest first.
     *
     * @throws AuthenticationException
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('viewAny', NoCreditDeclaration::class);

        $declarations = NoCreditDeclaration::query()
            ->visibleTo($actor)
            ->whereNull('confirmed_at')
            ->with('student.user')
            ->orderBy('declared_at')
            ->orderBy('id')
            ->get();

        return $this->cachePrivateResponse(NoCreditDeclarationResource::collection($declarations)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function confirm(
        Request $request,
        NoCreditDeclaration $noCreditDeclaration,
        ConfirmNoCredit $confirmer,
        SendQueueLinks $sendQueueLinks,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('confirm', $noCreditDeclaration);

        $context = $contextFactory->fromRequest($request);
        $outbox = new QueueLinkOutbox;
        $declaration = $confirmer->execute($noCreditDeclaration, $actor, $context, $outbox);

        // After commit: a transferee confirmed here was given their queue number.
        $sendQueueLinks->handle($outbox, $actor, $context);

        return $this->cachePrivateResponse(NoCreditDeclarationResource::make($declaration)->response($request));
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
     * `private`: a student's credit mapping is part of their academic record, so no shared cache may keep it.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
