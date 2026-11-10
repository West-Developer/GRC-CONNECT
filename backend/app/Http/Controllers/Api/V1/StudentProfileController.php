<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\RotateFreshmanQueueToken;
use App\Actions\Enrollment\SendFreshmanQueueTicketEmail;
use App\Actions\Identity\AdmitStudent;
use App\Actions\Identity\AssignStudentNumber;
use App\Actions\Identity\ListAdmissionStudentProfiles;
use App\Actions\Identity\SendStudentAccountSetupInvitation;
use App\Actions\Identity\UpdateStudentProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StudentProfile\IndexStudentProfileRequest;
use App\Http\Requests\Api\V1\StudentProfile\StoreStudentProfileRequest;
use App\Http\Requests\Api\V1\StudentProfile\UpdateStudentProfileRequest;
use App\Http\Resources\Api\V1\StudentProfileResource;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class StudentProfileController extends Controller
{
    public function index(
        IndexStudentProfileRequest $request,
        ListAdmissionStudentProfiles $listProfiles,
    ): JsonResponse {
        $this->authorize('viewAny', StudentProfile::class);

        return $this->cachePrivateResponse(
            StudentProfileResource::collection($listProfiles->handle($request->validated()))->response($request),
        );
    }

    /**
     * The number the next new account would get (ADR 0042). A preview only: it reserves nothing,
     * and the number is taken when the account is actually created. Same permission as creating one.
     */
    public function nextStudentNumber(AssignStudentNumber $assignStudentNumber): JsonResponse
    {
        $this->authorize('create', StudentProfile::class);

        return $this->cachePrivateResponse(response()->json([
            'data' => [
                'type' => 'next_student_number',
                'student_number' => $assignStudentNumber->preview(),
            ],
        ]));
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreStudentProfileRequest $request,
        AdmitStudent $admitStudent,
        SendStudentAccountSetupInvitation $sendInvitation,
        SendFreshmanQueueTicketEmail $sendQueueTicketEmail,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('create', StudentProfile::class);

        // The student and, for a freshman, their queue number are recorded together or not at all.
        $admitted = $admitStudent->handle([
            'first_name' => $request->validated('first_name'),
            'middle_name' => $request->validated('middle_name'),
            'last_name' => $request->validated('last_name'),
            'suffix' => $request->validated('suffix'),
            'email' => $request->validated('email'),
            'gender' => $request->validated('gender'),
            'birth_date' => $request->validated('birth_date'),
            'citizenship' => $request->validated('citizenship'),
            'contact_number' => $request->validated('contact_number'),
            'street_barangay' => $request->validated('street_barangay'),
            'town_city' => $request->validated('town_city'),
            'province' => $request->validated('province'),
            'has_existing_student_number' => $request->boolean('has_existing_student_number'),
            'student_number' => $request->validated('student_number'),
            'program_id' => $request->validated('program_id'),
            'entry_year' => $request->validated('entry_year'),
            'year_level' => $request->validated('year_level'),
            'student_type' => $request->validated('student_type'),
            'financial_status' => $request->validated('financial_status'),
            // Null when the caller sent no list, so the older single confirmation still works.
            'requirement_type_ids' => $request->validated('requirement_type_ids'),
            // Validated against the current version: Admission showed the applicant the Data Privacy Notice.
            'privacy_notice_version' => $request->validated('privacy_notice_version'),
        ], $actor, $contextFactory->fromRequest($request));

        $profile = $admitted->profile;

        if ($admitted->queueTicket !== null) {
            // A freshman pays the Cashier first and gets their account after that, so
            // instead of the setup email they receive a queue number and a link to the
            // live queue. The setup email is sent when the first payment is confirmed.
            // Sent now, after the commit: an email cannot be rolled back.
            if ($admitted->queueTicket->plainToken !== null) {
                $sendQueueTicketEmail->handle($admitted->queueTicket->ticket, $admitted->queueTicket->plainToken, $actor, $contextFactory->fromRequest($request));
            }
        } else {
            $sendInvitation->handle($profile, $actor, $contextFactory->fromRequest($request));
        }
        $profile->refresh()->load('queueTicket');

        $response = StudentProfileResource::make($profile)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $profile = StudentProfile::query()
            ->with(['user', 'program', 'curriculum', 'queueTicket'])
            ->withExists('enrollments')
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->authorize('view', $profile);

        return $this->cachePrivateResponse(StudentProfileResource::make($profile)->response($request));
    }

    public function showForAdmission(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $this->authorize('view', $studentProfile);
        $studentProfile->load(['user', 'program', 'curriculum', 'queueTicket'])->loadExists('enrollments');

        return $this->cachePrivateResponse(StudentProfileResource::make($studentProfile)->response($request));
    }

    public function showForRegistrar(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $this->authorize('viewForRegistrar', $studentProfile);
        $studentProfile->load(['user', 'program', 'curriculum', 'queueTicket'])->loadExists('enrollments');

        return $this->cachePrivateResponse(StudentProfileResource::make($studentProfile)->response($request));
    }

    public function update(
        UpdateStudentProfileRequest $request,
        StudentProfile $studentProfile,
        UpdateStudentProfile $updateProfile,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('update', $studentProfile);

        $updated = $updateProfile->handle(
            $studentProfile,
            $request->validated(),
            $actor,
            $contextFactory->fromRequest($request),
        );
        $updated->load('queueTicket')->loadExists('enrollments');

        return $this->cachePrivateResponse(StudentProfileResource::make($updated)->response($request));
    }

    public function resendSetupInvitation(
        Request $request,
        StudentProfile $studentProfile,
        SendStudentAccountSetupInvitation $sendInvitation,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('update', $studentProfile);
        $studentProfile->load('user');
        $sendInvitation->handle($studentProfile, $actor, $contextFactory->fromRequest($request));
        $studentProfile->refresh()->load(['user', 'program', 'curriculum', 'queueTicket'])->loadExists('enrollments');

        return $this->cachePrivateResponse(StudentProfileResource::make($studentProfile)->response($request));
    }

    /**
     * Sends a freshman's "view the live queue" link again. The old link stops
     * working (only the token's hash is stored, so the new email carries a new one).
     */
    public function resendQueueLink(
        Request $request,
        StudentProfile $studentProfile,
        RotateFreshmanQueueToken $rotateToken,
        SendFreshmanQueueTicketEmail $sendQueueTicketEmail,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('update', $studentProfile);

        $ticket = $studentProfile->queueTicket;

        if ($ticket === null) {
            throw ValidationException::withMessages(['student' => 'This student has no queue number to send a link for.']);
        }

        $plainToken = $rotateToken->execute($ticket);
        $sendQueueTicketEmail->handle($ticket->refresh(), $plainToken, $actor, $contextFactory->fromRequest($request));
        $studentProfile->refresh()->load(['user', 'program', 'curriculum', 'queueTicket'])->loadExists('enrollments');

        return $this->cachePrivateResponse(StudentProfileResource::make($studentProfile)->response($request));
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
     * `private`: this is one user's own record — no shared cache may retain
     * any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
