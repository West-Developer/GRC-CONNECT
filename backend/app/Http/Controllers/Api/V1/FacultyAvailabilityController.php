<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Faculty\CreateFacultyAvailability;
use App\Actions\Faculty\DeleteFacultyAvailability;
use App\Actions\Faculty\UpdateFacultyAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FacultyAvailability\StoreFacultyAvailabilityRequest;
use App\Http\Requests\Api\V1\FacultyAvailability\UpdateFacultyAvailabilityRequest;
use App\Http\Resources\Api\V1\FacultyAvailabilityResource;
use App\Models\FacultyAvailability;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FacultyAvailabilityController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $this->authorize('viewAny', FacultyAvailability::class);

        $availabilities = FacultyAvailability::query()
            ->visibleTo($user)
            ->orderBy('day_of_week')
            ->orderBy('starts_at_time')
            ->get();

        return $this->cachePrivateResponse(FacultyAvailabilityResource::collection($availabilities)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreFacultyAvailabilityRequest $request,
        CreateFacultyAvailability $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('create', FacultyAvailability::class);

        $availability = $action->execute($user, [
            'day_of_week' => $request->validated('day_of_week'),
            'starts_at_time' => $request->validated('starts_at_time'),
            'ends_at_time' => $request->validated('ends_at_time'),
        ], $contextFactory->fromRequest($request));

        $response = FacultyAvailabilityResource::make($availability)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateFacultyAvailabilityRequest $request,
        FacultyAvailability $facultyAvailability,
        UpdateFacultyAvailability $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $facultyAvailability);

        $availability = $action->execute($user, [
            'day_of_week' => $request->validated('day_of_week'),
            'starts_at_time' => $request->validated('starts_at_time'),
            'ends_at_time' => $request->validated('ends_at_time'),
        ], $facultyAvailability, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(FacultyAvailabilityResource::make($availability)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function destroy(
        Request $request,
        FacultyAvailability $facultyAvailability,
        DeleteFacultyAvailability $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('delete', $facultyAvailability);

        $action->execute($user, $facultyAvailability, $contextFactory->fromRequest($request));

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
