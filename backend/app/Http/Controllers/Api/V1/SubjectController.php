<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SubjectResource;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SubjectController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('viewAny', Subject::class);

        $subjects = Subject::query()
            ->visibleTo($user)
            ->orderBy('code')
            ->get();

        $response = SubjectResource::collection($subjects)->response($request);

        // `private`: a student and a program chair receive different bodies
        // from this same URL, so no shared cache may retain either response.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
