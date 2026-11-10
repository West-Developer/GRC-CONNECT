<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\TransitionQueueCycle;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\QueueCycleResource;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRD §5.3 FR-FIN-006. Reuses `QueueTicketPolicy::viewAny`/`::update`
 * rather than a new Policy class — both cut-off/resume and the existing
 * queue-ticket transitions are the exact same rule (Accounting Staff only,
 * no per-record ownership dimension), applied as defense in depth under
 * the route-level `role:accounting_staff` gate (see `routes/api.php`).
 */
final class QueueCycleController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function show(Request $request): JsonResponse
    {
        $this->authenticatedUser($request);
        $this->authorize('viewAny', QueueTicket::class);

        $cycle = QueueCycle::query()->whereNull('closed_at')->first();
        $response = response()->json([
            'data' => $cycle === null ? null : (new QueueCycleResource($cycle))->resolve($request),
        ]);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function cutOff(Request $request, TransitionQueueCycle $transitioner, AuditRequestContextFactory $contextFactory): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('update', QueueTicket::class);

        $cycle = $transitioner->cutOff($actor, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(QueueCycleResource::make($cycle)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function resume(Request $request, TransitionQueueCycle $transitioner, AuditRequestContextFactory $contextFactory): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('update', QueueTicket::class);

        $cycle = $transitioner->resume($actor, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(QueueCycleResource::make($cycle)->response($request));
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

    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
