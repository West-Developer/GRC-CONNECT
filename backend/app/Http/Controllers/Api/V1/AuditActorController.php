<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\ListAuditActors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AuditLog\IndexAuditActorRequest;
use App\Http\Resources\Api\V1\AuditActorResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

/**
 * The audit screen's per-user summary (Registrar Head only).
 */
final class AuditActorController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(
        IndexAuditActorRequest $request,
        ListAuditActors $listAuditActors,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('viewAny', AuditLog::class);

        $actors = $listAuditActors->execute(
            $actor,
            $request->validated(),
            $contextFactory->fromRequest($request),
        );

        $response = AuditActorResource::collection($actors)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
