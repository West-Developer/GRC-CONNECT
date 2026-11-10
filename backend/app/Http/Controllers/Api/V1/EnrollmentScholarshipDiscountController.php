<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ApplyScholarshipDiscount;
use App\Actions\Billing\RemoveScholarshipDiscount;
use App\Domain\Billing\ScholarshipTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Enrollment\UpdateScholarshipDiscountRequest;
use App\Http\Resources\Api\V1\EnrollmentResource;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Cashier's Payee / Scholar choice at payment time (ADR 0025). Authorized
 * like the fee adjustment it sits beside: Accounting Staff only, and the
 * actions themselves refuse anything but a `pending_payment` enrollment with no
 * confirmed payment.
 */
final class EnrollmentScholarshipDiscountController extends Controller
{
    /** @throws AuthenticationException */
    public function update(
        UpdateScholarshipDiscountRequest $request,
        Enrollment $enrollment,
        ApplyScholarshipDiscount $applyScholarshipDiscount,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('adjustAssessment', Enrollment::class);

        $updated = $applyScholarshipDiscount->execute(
            $enrollment,
            ScholarshipTier::from((int) $request->validated('percentage')),
            $actor,
            $contextFactory->fromRequest($request),
        );

        return $this->cachePrivateResponse(EnrollmentResource::make($updated)->response($request));
    }

    /** @throws AuthenticationException */
    public function destroy(
        Request $request,
        Enrollment $enrollment,
        RemoveScholarshipDiscount $removeScholarshipDiscount,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('adjustAssessment', Enrollment::class);

        $updated = $removeScholarshipDiscount->execute($enrollment, $actor, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(EnrollmentResource::make($updated)->response($request));
    }

    /** @throws AuthenticationException */
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
