<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\ListClassRoster;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClassRoster\IndexClassRosterRequest;
use App\Http\Resources\Api\V1\ClassRosterEntryResource;
use App\Models\EnrollmentSubject;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClassRosterController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(IndexClassRosterRequest $request, ListClassRoster $listClassRoster): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('viewAny', EnrollmentSubject::class);

        $entries = $listClassRoster->execute($actor, $request->validated());

        $response = ClassRosterEntryResource::collection($entries)->response($request);

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
     * `private`: a class roster carries student identity — no shared cache
     * may retain any response from this endpoint.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
