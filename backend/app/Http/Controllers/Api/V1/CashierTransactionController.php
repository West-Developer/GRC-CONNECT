<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ListCashierTransactions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CashierTransaction\IndexCashierTransactionRequest;
use App\Http\Resources\Api\V1\CashierTransactionResource;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CashierTransactionController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(
        IndexCashierTransactionRequest $request,
        ListCashierTransactions $listCashierTransactions,
    ): JsonResponse {
        $this->authenticatedUser($request);
        $this->authorize('viewAny', Payment::class);

        $response = CashierTransactionResource::collection(
            $listCashierTransactions->execute($request->validated()),
        )->response($request);
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
