<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\ConfirmFreshmanPayment;
use App\Actions\Enrollment\PreviewFreshmanPayment;
use App\Actions\Identity\SendStudentAccountSetupInvitation;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\FreshmanPaymentResult;
use App\Domain\Identity\StudentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StudentProfile\ConfirmFreshmanPaymentRequest;
use App\Http\Resources\Api\V1\FreshmanPaymentPreviewResource;
use App\Http\Resources\Api\V1\FreshmanPaymentResource;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Cashier's first-payment confirmation for a freshman, and the read-only preview
 * of what it will do (ADR 0043). Accounting Staff only (`StudentProfilePolicy`).
 */
final class FreshmanPaymentController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function preview(Request $request, StudentProfile $studentProfile, PreviewFreshmanPayment $previewFreshmanPayment): JsonResponse
    {
        $this->authenticatedUser($request);
        $this->authorize('confirmFreshmanPayment', $studentProfile);

        $response = FreshmanPaymentPreviewResource::make($previewFreshmanPayment->execute($studentProfile))->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * 201 the first time, 200 on a repeat (nothing new was created and nothing is sent again).
     *
     * @throws AuthenticationException
     */
    public function store(
        ConfirmFreshmanPaymentRequest $request,
        StudentProfile $studentProfile,
        ConfirmFreshmanPayment $confirmFreshmanPayment,
        SendStudentAccountSetupInvitation $sendAccountSetup,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('confirmFreshmanPayment', $studentProfile);

        $context = $contextFactory->fromRequest($request);
        $result = $confirmFreshmanPayment->execute($studentProfile, $request->validated(), $actor, $context);

        if ($result->created) {
            $this->sendEmails($result, $actor, $context, $sendAccountSetup);
        }

        $response = FreshmanPaymentResource::make($result)->response($request);
        $response->setStatusCode($result->created ? 201 : 200);

        return $this->cachePrivateResponse($response);
    }

    /**
     * After commit, and never able to undo the payment: the freshman's account setup email
     * (their account exists from now on). The enrolled email is NOT sent here (2026-10-04): a
     * freshman is enrolled only when the Registrar approves, and that send lives in
     * `EnrollmentController::update`. The sender swallows and records a delivery failure.
     */
    private function sendEmails(
        FreshmanPaymentResult $result,
        User $actor,
        AuditRequestContext $context,
        SendStudentAccountSetupInvitation $sendAccountSetup,
    ): void {
        // A transferee already has a working account (they signed in to choose their subjects);
        // only a freshman's account is set up after the first payment.
        if ($result->profile->student_type !== StudentType::Freshman) {
            return;
        }

        try {
            $sendAccountSetup->handle($result->profile->loadMissing('user'), $actor, $context);
        } catch (ValidationException) {
            // The account is not in a state to receive a setup invitation (for example it is already active).
        }
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

    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
