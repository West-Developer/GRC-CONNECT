<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\NoCreditDeclaration;
use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What is waiting for the signed-in Program Chair, for the numbers on the Enrollment page's tabs: the irregular
 * enrollments to check, and the students whose credit mapping (their credits, or a "no subject to credit") is
 * still with the Chair. Counts only, scoped to the Chair's own college like the lists they open.
 */
final class ProgramChairReviewCountsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $actor = $this->authenticatedUser($request);

        $irregularAdvising = Enrollment::query()
            ->visibleTo($actor)
            ->where('status', EnrollmentStatus::PendingProgramHeadApproval->value)
            ->count();

        $studentsWithCredits = TransfereeCredit::query()
            ->visibleTo($actor)
            ->where('status', TransfereeCreditStatus::Pending->value)
            ->distinct()
            ->count('student_id');

        $noCreditDeclarations = NoCreditDeclaration::query()
            ->visibleTo($actor)
            ->whereNull('confirmed_at')
            ->count();

        $response = response()->json(['data' => [
            'type' => 'program_chair_review_counts',
            'irregular_advising' => $irregularAdvising,
            'credit_mappings' => $studentsWithCredits + $noCreditDeclarations,
        ]]);

        return $this->cachePrivateResponse($response);
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
