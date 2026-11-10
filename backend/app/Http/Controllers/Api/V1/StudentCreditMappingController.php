<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Academic\ApproveStudentCredits;
use App\Actions\Academic\BuildCreditMappingWorkspace;
use App\Actions\Academic\SubmitCreditsToRegistrarHead;
use App\Actions\Enrollment\SendQueueLinks;
use App\Domain\Enrollment\QueueLinkOutbox;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CreditSubjectSuggestionResource;
use App\Http\Resources\Api\V1\TransfereeCreditResource;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student-level credit mapping endpoints (ADR 0026, amended): everything
 * the Program Chair's side-by-side screen and the Registrar Head's review act
 * on for one student at once. Policy-gated like the per-credit routes
 * (`TransfereeCreditPolicy`), with no `role:` middleware.
 */
final class StudentCreditMappingController extends Controller
{
    /**
     * The system's suggestions for every credit of the student that still waits
     * for a mapping, plus the subjects of the student's own curriculum. Advice
     * for the Program Chair, computed on demand and never stored.
     */
    public function suggestions(
        Request $request,
        StudentProfile $studentProfile,
        BuildCreditMappingWorkspace $workspace,
    ): JsonResponse {
        $this->authorize('manageStudent', [TransfereeCredit::class, $studentProfile]);

        $built = $workspace->execute($studentProfile);

        $credits = [];
        foreach ($built['credits'] as $group) {
            $credits[] = [
                'credit_id' => $group['credit_id'],
                'suggestions' => CreditSubjectSuggestionResource::collection($group['suggestions'])->resolve($request),
            ];
        }

        return $this->cachePrivateResponse(new JsonResponse([
            'data' => [
                'student_id' => $studentProfile->id,
                'credits' => $credits,
                'curriculum_subjects' => $built['curriculum_subjects'],
            ],
        ]));
    }

    /**
     * The Program Chair sends the whole student to the Registrar Head. All or
     * nothing: a credit still without a mapped subject refuses the lot (422). A
     * repeat finds nothing to send and answers 200 with an empty list.
     *
     * @throws AuthenticationException
     */
    public function submitToRegistrarHead(
        Request $request,
        StudentProfile $studentProfile,
        SubmitCreditsToRegistrarHead $submitter,
        SendQueueLinks $sendQueueLinks,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('manageStudent', [TransfereeCredit::class, $studentProfile]);

        $context = $contextFactory->fromRequest($request);
        $outbox = new QueueLinkOutbox;
        $credits = $submitter->execute($studentProfile, $actor, $context, $outbox);

        // After commit: passing the last pending credits on issued the student's queue number.
        $sendQueueLinks->handle($outbox, $actor, $context);

        return $this->cachePrivateResponse(TransfereeCreditResource::collection($credits)->response($request));
    }

    /**
     * The Registrar Head approves every endorsed credit of the student at once.
     * Only endorsed credits are touched; a repeat finds none and answers 200
     * with an empty list.
     *
     * @throws AuthenticationException
     */
    public function approveAll(
        Request $request,
        StudentProfile $studentProfile,
        ApproveStudentCredits $approver,
        SendQueueLinks $sendQueueLinks,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('decide', TransfereeCredit::class);

        $context = $contextFactory->fromRequest($request);
        $outbox = new QueueLinkOutbox;
        $credits = $approver->execute($studentProfile, $actor, $context, $outbox);

        // After commit: approving the last open rows issued the student's queue number.
        $sendQueueLinks->handle($outbox, $actor, $context);

        return $this->cachePrivateResponse(TransfereeCreditResource::collection($credits)->response($request));
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
     * `private`: transferee credits carry an academic record — no shared cache
     * may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
