<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Curriculum\ReplaceSubjectOfferings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubjectOffering\IndexSubjectOfferingRequest;
use App\Http\Requests\Api\V1\SubjectOffering\ReplaceSubjectOfferingsRequest;
use App\Http\Resources\Api\V1\SubjectOfferingResource;
use App\Models\SubjectOffering;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SubjectOfferingController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(IndexSubjectOfferingRequest $request): JsonResponse
    {
        $this->authenticatedUser($request);

        $this->authorize('viewAny', SubjectOffering::class);

        $offerings = SubjectOffering::query()
            ->where('academic_term_id', $request->validated('academic_term_id'))
            ->where('curriculum_id', $request->validated('curriculum_id'))
            ->with('subject')
            ->orderBy('year_level')
            ->orderBy('semester')
            ->get();

        return $this->cachePrivateResponse(SubjectOfferingResource::collection($offerings)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        ReplaceSubjectOfferingsRequest $request,
        ReplaceSubjectOfferings $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('create', SubjectOffering::class);

        $offerings = $action->execute(
            $user,
            (int) $request->validated('academic_term_id'),
            (int) $request->validated('curriculum_id'),
            $request->offerings(),
            $contextFactory->fromRequest($request),
        );

        $offerings->load('subject');

        return $this->cachePrivateResponse(SubjectOfferingResource::collection($offerings)->response($request));
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
     * `private`: this is Program-Chair-only planning data, but still no
     * shared cache may retain a response tied to one actor's request.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
