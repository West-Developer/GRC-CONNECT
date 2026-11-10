<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Faculty\CreateFacultySubjectPreference;
use App\Actions\Faculty\DeleteFacultySubjectPreference;
use App\Actions\Faculty\UpdateFacultySubjectPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FacultySubjectPreference\StoreFacultySubjectPreferenceRequest;
use App\Http\Requests\Api\V1\FacultySubjectPreference\UpdateFacultySubjectPreferenceRequest;
use App\Http\Resources\Api\V1\FacultySubjectPreferenceResource;
use App\Models\FacultySubjectPreference;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FacultySubjectPreferenceController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $this->authorize('viewAny', FacultySubjectPreference::class);

        $preferences = FacultySubjectPreference::query()
            ->visibleTo($user)
            ->orderBy('academic_term_id')
            ->orderBy('rank')
            ->get();

        return $this->cachePrivateResponse(FacultySubjectPreferenceResource::collection($preferences)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreFacultySubjectPreferenceRequest $request,
        CreateFacultySubjectPreference $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('create', FacultySubjectPreference::class);

        $preference = $action->execute($user, [
            'academic_term_id' => $request->validated('academic_term_id'),
            'subject_id' => $request->validated('subject_id'),
            'rank' => $request->validated('rank'),
        ], $contextFactory->fromRequest($request));

        $response = FacultySubjectPreferenceResource::make($preference)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateFacultySubjectPreferenceRequest $request,
        FacultySubjectPreference $facultySubjectPreference,
        UpdateFacultySubjectPreference $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $facultySubjectPreference);

        $preference = $action->execute($user, [
            'academic_term_id' => $request->validated('academic_term_id'),
            'subject_id' => $request->validated('subject_id'),
            'rank' => $request->validated('rank'),
        ], $facultySubjectPreference, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(
            FacultySubjectPreferenceResource::make($preference)->response($request),
        );
    }

    /**
     * @throws AuthenticationException
     */
    public function destroy(
        Request $request,
        FacultySubjectPreference $facultySubjectPreference,
        DeleteFacultySubjectPreference $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('delete', $facultySubjectPreference);

        $action->execute($user, $facultySubjectPreference, $contextFactory->fromRequest($request));

        $response = new JsonResponse(null, 204);
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

    /**
     * `private`: results and authoring rights differ by role, so no shared
     * cache may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
