<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Identity\DecideStudentProfileChangeRequest;
use App\Actions\Identity\ManageStudentProfileChangeRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StudentProfileChangeRequest\DecideStudentProfileChangeRequestRequest;
use App\Http\Requests\Api\V1\StudentProfileChangeRequest\IndexStudentProfileChangeRequestRequest;
use App\Http\Requests\Api\V1\StudentProfileChangeRequest\StoreStudentProfileChangeRequestRequest;
use App\Http\Resources\Api\V1\StudentProfileChangeRequestResource;
use App\Models\StudentProfileChangeRequest;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class StudentProfileChangeRequestController extends Controller
{
    public function index(
        IndexStudentProfileChangeRequestRequest $request,
        ManageStudentProfileChangeRequest $manager,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorize('viewAny', StudentProfileChangeRequest::class);
        $response = StudentProfileChangeRequestResource::collection(
            $manager->list($request->validated(), $actor),
        )->response($request);

        return $this->private($response);
    }

    public function store(
        StoreStudentProfileChangeRequestRequest $request,
        ManageStudentProfileChangeRequest $manager,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorize('create', StudentProfileChangeRequest::class);
        $changeRequest = $manager->create([
            'first_name' => $request->validated('first_name'),
            'middle_name' => $request->validated('middle_name'),
            'last_name' => $request->validated('last_name'),
            'suffix' => $request->validated('suffix'),
            'email' => $request->validated('email'),
            'address' => $request->validated('address'),
            'reason' => $request->validated('reason'),
        ], $actor, $contextFactory->fromRequest($request));
        $response = StudentProfileChangeRequestResource::make($changeRequest)->response($request);
        $response->setStatusCode(201);

        return $this->private($response);
    }

    public function update(
        StoreStudentProfileChangeRequestRequest $request,
        StudentProfileChangeRequest $studentProfileChangeRequest,
        ManageStudentProfileChangeRequest $manager,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->actor($request);
        $studentProfileChangeRequest->load('student');
        $this->authorize('update', $studentProfileChangeRequest);

        return $this->private(StudentProfileChangeRequestResource::make(
            $manager->revise($studentProfileChangeRequest, [
                'first_name' => $request->validated('first_name'),
                'middle_name' => $request->validated('middle_name'),
                'last_name' => $request->validated('last_name'),
                'suffix' => $request->validated('suffix'),
                'email' => $request->validated('email'),
                'address' => $request->validated('address'),
                'reason' => $request->validated('reason'),
            ], $actor, $contextFactory->fromRequest($request)),
        )->response($request));
    }

    public function destroy(
        IndexStudentProfileChangeRequestRequest $request,
        StudentProfileChangeRequest $studentProfileChangeRequest,
        ManageStudentProfileChangeRequest $manager,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->actor($request);
        $studentProfileChangeRequest->load('student');
        $this->authorize('cancel', $studentProfileChangeRequest);

        return $this->private(StudentProfileChangeRequestResource::make(
            $manager->cancel($studentProfileChangeRequest, $actor, $contextFactory->fromRequest($request)),
        )->response($request));
    }

    public function decide(
        DecideStudentProfileChangeRequestRequest $request,
        StudentProfileChangeRequest $studentProfileChangeRequest,
        DecideStudentProfileChangeRequest $decide,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }
        $this->authorize('decide', $studentProfileChangeRequest);

        return $this->private(StudentProfileChangeRequestResource::make(
            $decide->handle($studentProfileChangeRequest, [
                'action' => $request->validated('action'),
                'identity_verified_in_person' => $request->validated('identity_verified_in_person'),
                'notes' => $request->validated('notes'),
            ], $actor, $contextFactory->fromRequest($request)),
        )->response($request));
    }

    private function actor(IndexStudentProfileChangeRequestRequest|StoreStudentProfileChangeRequestRequest $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        return $actor;
    }

    private function private(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
