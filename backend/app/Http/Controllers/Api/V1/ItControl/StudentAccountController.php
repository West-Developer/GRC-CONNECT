<?php

namespace App\Http\Controllers\Api\V1\ItControl;

use App\Actions\ItControl\ListStudentAccounts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ItControl\IndexStudentAccountRequest;
use App\Http\Resources\Api\V1\ItControl\StudentAccountResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class StudentAccountController extends Controller
{
    /** @throws AuthenticationException */
    public function __invoke(IndexStudentAccountRequest $request, ListStudentAccounts $listStudentAccounts): JsonResponse
    {
        if (! $request->user() instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('view-it-control-account-browser');

        $response = StudentAccountResource::collection(
            $listStudentAccounts->execute($request->validated()),
        )->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
