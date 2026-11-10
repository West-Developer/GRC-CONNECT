<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\ListAuditLogs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AuditLog\IndexAuditLogRequest;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class AuditLogController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(
        IndexAuditLogRequest $request,
        ListAuditLogs $listAuditLogs,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('viewAny', AuditLog::class);

        $auditLogs = $listAuditLogs->execute(
            $actor,
            $request->validated(),
            $contextFactory->fromRequest($request),
        );

        $response = AuditLogResource::collection($auditLogs)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
