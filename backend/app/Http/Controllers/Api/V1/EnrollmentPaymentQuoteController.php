<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\QuoteFullPayment;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentQuoteResource;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Cashier's read-only quote for an enrollment's payment (ADR 0046): the amount due and what
 * paying in full would collect. Authorized like confirming the payment (Accounting Staff).
 */
final class EnrollmentPaymentQuoteController extends Controller
{
    /** @throws AuthenticationException */
    public function __invoke(Request $request, Enrollment $enrollment, QuoteFullPayment $quoteFullPayment): JsonResponse
    {
        if (! $request->user() instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('confirmPayment', Enrollment::class);

        $assessment = Assessment::query()->where('enrollment_id', $enrollment->id)->with('items')->first();

        if ($enrollment->status !== EnrollmentStatus::PendingPayment || ! $assessment instanceof Assessment) {
            throw ValidationException::withMessages([
                'enrollment' => 'A payment quote is only available for an enrollment that is pending payment and has an assessment.',
            ]);
        }

        $response = PaymentQuoteResource::make($quoteFullPayment->forAssessment($assessment))->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
