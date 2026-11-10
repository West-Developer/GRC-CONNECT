<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Faculty\CreateFacultySpecialization;
use App\Actions\Faculty\DecideFacultySpecialization;
use App\Actions\Faculty\DeleteFacultySpecialization;
use App\Actions\Faculty\UpdateFacultySpecializationProficiency;
use App\Domain\Faculty\SpecializationProficiency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FacultySpecialization\DecideFacultySpecializationRequest;
use App\Http\Requests\Api\V1\FacultySpecialization\StoreFacultySpecializationRequest;
use App\Http\Requests\Api\V1\FacultySpecialization\UpdateFacultySpecializationProficiencyRequest;
use App\Http\Resources\Api\V1\FacultySpecializationResource;
use App\Models\FacultySpecialization;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FacultySpecializationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', FacultySpecialization::class);

        $specializations = FacultySpecialization::query()
            ->visibleTo($user)
            ->when(
                $request->filled('professor_id'),
                fn ($query) => $query->where('professor_id', (int) $request->query('professor_id')),
            )
            ->orderBy('proficiency')
            ->orderBy('subject_id')
            ->get();

        return $this->privateResponse(FacultySpecializationResource::collection($specializations)->response($request));
    }

    public function store(
        StoreFacultySpecializationRequest $request,
        CreateFacultySpecialization $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('create', FacultySpecialization::class);
        $specialization = $action->execute($user, $request->validated(), $contextFactory->fromRequest($request));

        $response = FacultySpecializationResource::make($specialization)->response($request);
        $response->setStatusCode(201);

        return $this->privateResponse($response);
    }

    public function update(
        DecideFacultySpecializationRequest $request,
        FacultySpecialization $facultySpecialization,
        DecideFacultySpecialization $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('decide', $facultySpecialization);

        $specialization = $action->execute(
            $facultySpecialization,
            $request->validated('action'),
            $user,
            $request->validated('reason'),
            $contextFactory->fromRequest($request),
        );

        return $this->privateResponse(FacultySpecializationResource::make($specialization)->response($request));
    }

    public function updateProficiency(
        UpdateFacultySpecializationProficiencyRequest $request,
        FacultySpecialization $facultySpecialization,
        UpdateFacultySpecializationProficiency $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('updateProficiency', $facultySpecialization);

        $specialization = $action->execute(
            $user,
            $facultySpecialization,
            SpecializationProficiency::from($request->validated('proficiency')),
            $contextFactory->fromRequest($request),
        );

        return $this->privateResponse(FacultySpecializationResource::make($specialization)->response($request));
    }

    public function destroy(
        Request $request,
        FacultySpecialization $facultySpecialization,
        DeleteFacultySpecialization $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('delete', $facultySpecialization);
        $action->execute($user, $facultySpecialization, $contextFactory->fromRequest($request));

        return $this->privateResponse(new JsonResponse(null, 204));
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function privateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
