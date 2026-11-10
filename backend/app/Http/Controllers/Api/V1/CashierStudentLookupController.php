<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\FindCashierStudents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CashierStudentLookup\IndexCashierStudentLookupRequest;
use App\Http\Resources\Api\V1\CashierStudentResource;
use Illuminate\Http\JsonResponse;

final class CashierStudentLookupController extends Controller
{
    public function index(
        IndexCashierStudentLookupRequest $request,
        FindCashierStudents $findCashierStudents,
    ): JsonResponse {
        $this->authorize('search-cashier-students');

        $response = CashierStudentResource::collection(
            $findCashierStudents->execute((string) $request->validated('search')),
        )->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
