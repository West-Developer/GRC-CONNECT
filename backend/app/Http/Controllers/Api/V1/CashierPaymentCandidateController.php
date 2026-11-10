<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\FindCashierPaymentCandidate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CashierPaymentCandidate\ShowCashierPaymentCandidateRequest;
use App\Http\Resources\Api\V1\CashierPaymentCandidateResource;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CashierPaymentCandidateController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function show(
        ShowCashierPaymentCandidateRequest $request,
        FindCashierPaymentCandidate $findCashierPaymentCandidate,
    ): JsonResponse {
        $this->authenticatedUser($request);
        $this->authorize('viewAny', QueueTicket::class);

        $response = (new CashierPaymentCandidateResource(
            $findCashierPaymentCandidate->execute((string) $request->validated('student_number')),
        ))->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
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
}
