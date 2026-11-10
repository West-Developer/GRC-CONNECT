<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\GrantSubjectWaiver;
use App\Actions\Enrollment\ListSubjectWaiverOverview;
use App\Actions\Enrollment\RevokeSubjectWaiver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Enrollment\IndexSubjectWaiverRequest;
use App\Http\Requests\Api\V1\Enrollment\StoreSubjectWaiverRequest;
use App\Http\Resources\Api\V1\SubjectWaiverResource;
use App\Models\AcademicTerm;
use App\Models\EnrollmentSubjectWaiver;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Registrar Head's prerequisite waivers (stakeholder Doc 14, ADR 0031):
 * list what is granted and what is blocked, grant one, take one back.
 */
final class SubjectWaiverController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(
        IndexSubjectWaiverRequest $request,
        StudentProfile $studentProfile,
        ListSubjectWaiverOverview $overview,
    ): JsonResponse {
        $this->authenticatedUser($request);
        $this->authorize('manage', EnrollmentSubjectWaiver::class);

        $term = AcademicTerm::query()->findOrFail((int) $request->validated('academic_term_id'));
        $result = $overview->execute($studentProfile, $term);

        $response = SubjectWaiverResource::collection($result['waivers'])
            ->additional(['meta' => ['blocked_subjects' => $result['blocked_subjects']]])
            ->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * 201 when a waiver is created or re-activated, 200 when it was already
     * active (nothing changed).
     *
     * @throws AuthenticationException
     */
    public function store(
        StoreSubjectWaiverRequest $request,
        StudentProfile $studentProfile,
        GrantSubjectWaiver $grant,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('manage', EnrollmentSubjectWaiver::class);

        $term = AcademicTerm::query()->findOrFail((int) $request->validated('academic_term_id'));
        $result = $grant->execute(
            $studentProfile,
            (int) $request->validated('subject_id'),
            $term,
            (string) $request->validated('reason'),
            $actor,
            $contextFactory->fromRequest($request),
        );

        $response = SubjectWaiverResource::make($result['waiver'])->response($request);
        $response->setStatusCode($result['changed'] ? 201 : 200);

        return $this->cachePrivateResponse($response);
    }

    /**
     * Revokes (does not delete) the waiver and returns it.
     *
     * @throws AuthenticationException
     */
    public function destroy(
        Request $request,
        EnrollmentSubjectWaiver $waiver,
        RevokeSubjectWaiver $revoke,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('manage', EnrollmentSubjectWaiver::class);

        $waiver = $revoke->execute($waiver, $actor, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(SubjectWaiverResource::make($waiver)->response($request));
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
     * `private`: waivers name a student's academic exceptions — no shared
     * cache may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
