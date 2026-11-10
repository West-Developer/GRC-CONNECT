<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\AdjustEnrollmentAssessment;
use App\Actions\Enrollment\BuildCorSnapshot;
use App\Actions\Enrollment\ConfirmPayment;
use App\Actions\Enrollment\ListEnrollments;
use App\Actions\Enrollment\RequestWithdrawal;
use App\Actions\Enrollment\ReviseEnrollmentSubjects;
use App\Actions\Enrollment\SendEnrollmentConfirmationEmail;
use App\Actions\Enrollment\SubmitEnrollment;
use App\Actions\Enrollment\TransitionEnrollment;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Enrollment\AdjustEnrollmentAssessmentRequest;
use App\Http\Requests\Api\V1\Enrollment\ConfirmPaymentRequest;
use App\Http\Requests\Api\V1\Enrollment\IndexEnrollmentRequest;
use App\Http\Requests\Api\V1\Enrollment\ReviseEnrollmentSubjectsRequest;
use App\Http\Requests\Api\V1\Enrollment\StoreEnrollmentRequest;
use App\Http\Requests\Api\V1\Enrollment\UpdateEnrollmentRequest;
use App\Http\Requests\Api\V1\WithdrawalRequest\StoreWithdrawalRequestRequest;
use App\Http\Resources\Api\V1\EnrollmentResource;
use App\Http\Resources\Api\V1\PaymentConfirmationResource;
use App\Http\Resources\Api\V1\WithdrawalRequestResource;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EnrollmentController extends Controller
{
    /**
     * Which Policy ability governs each transition action — see
     * EnrollmentPolicy and ADR 0011 for why one route serves two Registrar
     * Head checkpoints instead of a single `role:` middleware.
     *
     * @var array<string, string>
     */
    private const ABILITY_FOR_ACTION = [
        'program_head_approve' => 'decideProgramHeadApproval',
        'program_head_reject' => 'decideProgramHeadApproval',
        'registrar_approve' => 'decideApproval',
        'registrar_reject' => 'decideApproval',
        'void' => 'void',
        'student_cancel' => 'cancel',
        'student_accept_revision' => 'respondToRevision',
        'student_decline_revision' => 'respondToRevision',
    ];

    /**
     * @throws AuthenticationException
     */
    public function index(IndexEnrollmentRequest $request, ListEnrollments $listEnrollments): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->authorize('viewAny', Enrollment::class);

        $enrollments = $listEnrollments->execute($actor, $request->validated());

        $response = EnrollmentResource::collection($enrollments)->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreEnrollmentRequest $request,
        SubmitEnrollment $submitEnrollment,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('create', Enrollment::class);

        $student = StudentProfile::query()->where('user_id', $actor->id)->firstOrFail();
        $term = AcademicTerm::query()->where('id', $request->validated('academic_term_id'))->firstOrFail();

        // For a block submission, `resolvedSectionIds()` is the server-side
        // expansion of `block_code` — the client never supplies section ids
        // directly for a block.
        $sectionIds = $request->resolvedSectionIds();
        $blockCode = $request->validated('block_code');

        $enrollment = $submitEnrollment->execute(
            $student,
            $term,
            $sectionIds,
            $actor,
            $contextFactory->fromRequest($request),
            is_string($blockCode) ? $blockCode : null,
        );

        $response = EnrollmentResource::make($enrollment)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateEnrollmentRequest $request,
        Enrollment $enrollment,
        TransitionEnrollment $transitioner,
        AuditRequestContextFactory $contextFactory,
        SendEnrollmentConfirmationEmail $sendConfirmationEmail,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);

        $action = $request->validated('action');
        $ability = self::ABILITY_FOR_ACTION[$action];

        // The Program Head's decision is record-level (own college only); the
        // Registrar-side abilities are role-level.
        $this->authorize(
            $ability,
            in_array($ability, ['decideProgramHeadApproval', 'cancel', 'respondToRevision'], true) ? $enrollment : Enrollment::class,
        );

        $context = $contextFactory->fromRequest($request);
        $enrollment = $transitioner->execute(
            $enrollment,
            $action,
            $actor,
            $request->validated('reason'),
            $context,
            $request->boolean('requested_by_student'),
            $request->validated('program_head_comment'),
        );

        // ADR 0043: approving a freshman's enrollment can finish it from the payment they already
        // made (enrolled at once). Then the same confirmation email a Cashier payment sends goes out,
        // after the transition has committed.
        if ($action === 'registrar_approve' && $enrollment->status === EnrollmentStatus::Enrolled) {
            $document = $enrollment->documents()->where('document_type', EnrollmentDocumentType::Cor)->first();
            if ($document !== null) {
                $sendConfirmationEmail->handle($enrollment, $document, $actor, $context);
            }
        }

        $response = EnrollmentResource::make($enrollment)->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * Program Head adding/removing whole subjects from a student's proposed
     * schedule while it sits at their own review stage (owner-requested,
     * 2026-09-28). See `ReviseEnrollmentSubjects` for the full rules.
     *
     * @throws AuthenticationException
     */
    public function reviseSubjects(
        ReviseEnrollmentSubjectsRequest $request,
        Enrollment $enrollment,
        ReviseEnrollmentSubjects $reviseEnrollmentSubjects,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('reviseSubjects', $enrollment);

        $updated = $reviseEnrollmentSubjects->execute(
            $enrollment,
            $request->resolvedSectionIds(),
            $actor,
            $contextFactory->fromRequest($request),
            (string) $request->validated('note'),
            $request->boolean('overload_acknowledged'),
        );

        return $this->cachePrivateResponse(EnrollmentResource::make($updated)->response($request));
    }

    /**
     * FR-FIN-007–009: idempotent — a repeat call returns the existing
     * payment/document rather than erroring or duplicating either. `201`
     * is returned only the first time; a repeat call gets `200` since
     * nothing new was created.
     *
     * @throws AuthenticationException
     */
    public function confirmPayment(
        ConfirmPaymentRequest $request,
        Enrollment $enrollment,
        ConfirmPayment $confirmPayment,
        SendEnrollmentConfirmationEmail $sendConfirmationEmail,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('confirmPayment', Enrollment::class);

        $context = $contextFactory->fromRequest($request);
        $result = $confirmPayment->execute($enrollment, $request->validated(), $actor, $context);

        // Only the call that actually confirmed payment sends the email — a
        // repeat/idempotent call (`created: false`) must never resend it.
        if ($result['created']) {
            $document = $result['enrollment']->documents->firstWhere('document_type', EnrollmentDocumentType::Cor);
            if ($document !== null) {
                $sendConfirmationEmail->handle($result['enrollment'], $document, $actor, $context);
            }
        }

        $response = PaymentConfirmationResource::make($result['enrollment'])->response($request);
        $response->setStatusCode($result['created'] ? 201 : 200);

        return $this->cachePrivateResponse($response);
    }

    /** @throws AuthenticationException */
    public function adjustAssessment(
        AdjustEnrollmentAssessmentRequest $request,
        Enrollment $enrollment,
        AdjustEnrollmentAssessment $adjustAssessment,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('adjustAssessment', Enrollment::class);

        $updated = $adjustAssessment->execute(
            $enrollment,
            $request->validated(),
            $actor,
            $contextFactory->fromRequest($request),
        );

        return $this->cachePrivateResponse(EnrollmentResource::make($updated)->response($request));
    }

    /**
     * FR-FIN-004 / PRD §4.2 rule 7: Student-only, own enrollment, only from
     * `enrolled`. Creates a `pending` `WithdrawalRequest` — never touches
     * the enrollment or releases a seat directly; that happens only once
     * Registrar Staff approves it (`WithdrawalRequestController::update`).
     *
     * @throws AuthenticationException
     */
    public function withdraw(
        StoreWithdrawalRequestRequest $request,
        Enrollment $enrollment,
        RequestWithdrawal $requestWithdrawal,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('withdraw', $enrollment);

        $withdrawalRequest = $requestWithdrawal->execute(
            $enrollment,
            $request->validated('reason'),
            $actor,
            $contextFactory->fromRequest($request),
        );

        $response = WithdrawalRequestResource::make($withdrawalRequest)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function corPreview(
        Request $request,
        Enrollment $enrollment,
        BuildCorSnapshot $buildCorSnapshot,
    ): JsonResponse {
        $this->authenticatedUser($request);
        $this->authorize('viewCorPreview', Enrollment::class);

        $enrollment->loadMissing([
            'student.user',
            'student.program',
            'academicTerm',
            'enrollmentSubjects.section.subject',
            'assessment.items',
            'payment.confirmer',
        ]);

        $snapshot = $buildCorSnapshot->execute($enrollment, $enrollment->payment);

        return $this->cachePrivateResponse(response()->json([
            'data' => [
                'snapshot' => $snapshot,
                'watermark' => 'Preview — official COR is issued after payment confirmation',
            ],
        ]));
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
     * `private`: this is one student's own enrollment data — no shared cache
     * may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
