<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ListPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payment\IndexPaymentRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(IndexPaymentRequest $request, ListPayments $listPayments): JsonResponse
    {
        $this->authenticatedUser($request);
        $this->authorize('viewAny', Payment::class);

        $payments = $listPayments->execute($request->validated());

        $response = PaymentResource::collection($payments)->response($request);
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
