<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\ListWithdrawalRequests;
use App\Actions\Enrollment\TransitionWithdrawalRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WithdrawalRequest\IndexWithdrawalRequestRequest;
use App\Http\Requests\Api\V1\WithdrawalRequest\UpdateWithdrawalRequestRequest;
use App\Http\Resources\Api\V1\WithdrawalRequestResource;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WithdrawalRequestController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(IndexWithdrawalRequestRequest $request, ListWithdrawalRequests $listWithdrawalRequests): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('viewAny', WithdrawalRequest::class);

        $requests = $listWithdrawalRequests->execute($actor, $request->validated());

        $response = WithdrawalRequestResource::collection($requests)->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateWithdrawalRequestRequest $request,
        WithdrawalRequest $withdrawalRequest,
        TransitionWithdrawalRequest $transitioner,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('decide', WithdrawalRequest::class);

        $withdrawalRequest = $transitioner->execute(
            $withdrawalRequest,
            $request->validated('action'),
            $actor,
            $request->validated('reason'),
            $contextFactory->fromRequest($request),
        );

        $response = WithdrawalRequestResource::make($withdrawalRequest)->response($request);

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
     * `private`: withdrawal requests carry an academic record — no shared
     * cache may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
