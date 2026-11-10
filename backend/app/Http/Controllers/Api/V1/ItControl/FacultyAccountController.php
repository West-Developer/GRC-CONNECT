<?php

namespace App\Http\Controllers\Api\V1\ItControl;

use App\Actions\ItControl\ListFacultyAccounts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ItControl\IndexFacultyAccountRequest;
use App\Http\Resources\Api\V1\ItControl\FacultyAccountResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class FacultyAccountController extends Controller
{
    /** @throws AuthenticationException */
    public function __invoke(IndexFacultyAccountRequest $request, ListFacultyAccounts $listFacultyAccounts): JsonResponse
    {
        if (! $request->user() instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('view-it-control-account-browser');

        $response = FacultyAccountResource::collection(
            $listFacultyAccounts->execute($request->validated()),
        )->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
