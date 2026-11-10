<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Faculty\CreateFacultyCurriculumSubjectPreference;
use App\Actions\Faculty\DeleteFacultyCurriculumSubjectPreference;
use App\Actions\Faculty\UpdateFacultyCurriculumSubjectPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FacultyCurriculumSubjectPreference\StoreFacultyCurriculumSubjectPreferenceRequest;
use App\Http\Requests\Api\V1\FacultyCurriculumSubjectPreference\UpdateFacultyCurriculumSubjectPreferenceRequest;
use App\Http\Resources\Api\V1\FacultyCurriculumSubjectPreferenceResource;
use App\Models\FacultyCurriculumSubjectPreference;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FacultyCurriculumSubjectPreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', FacultyCurriculumSubjectPreference::class);

        $preferences = FacultyCurriculumSubjectPreference::query()
            ->where('professor_id', $user->id)
            ->orderBy('curriculum_id')->orderBy('semester')->orderBy('rank')
            ->get();

        return $this->privateResponse(FacultyCurriculumSubjectPreferenceResource::collection($preferences)->response($request));
    }

    public function store(StoreFacultyCurriculumSubjectPreferenceRequest $request, CreateFacultyCurriculumSubjectPreference $action, AuditRequestContextFactory $contextFactory): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('create', FacultyCurriculumSubjectPreference::class);
        $preference = $action->execute($user, $request->validated(), $contextFactory->fromRequest($request));

        $response = FacultyCurriculumSubjectPreferenceResource::make($preference)->response($request);
        $response->setStatusCode(201);

        return $this->privateResponse($response);
    }

    public function update(UpdateFacultyCurriculumSubjectPreferenceRequest $request, FacultyCurriculumSubjectPreference $facultyCurriculumPreference, UpdateFacultyCurriculumSubjectPreference $action, AuditRequestContextFactory $contextFactory): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $facultyCurriculumPreference);
        $preference = $action->execute($user, $request->validated(), $facultyCurriculumPreference, $contextFactory->fromRequest($request));

        return $this->privateResponse(FacultyCurriculumSubjectPreferenceResource::make($preference)->response($request));
    }

    public function destroy(Request $request, FacultyCurriculumSubjectPreference $facultyCurriculumPreference, DeleteFacultyCurriculumSubjectPreference $action, AuditRequestContextFactory $contextFactory): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('delete', $facultyCurriculumPreference);
        $action->execute($user, $facultyCurriculumPreference, $contextFactory->fromRequest($request));

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
