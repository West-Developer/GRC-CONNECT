<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\ClaimQueueTicket;
use App\Actions\Enrollment\ListQueueTickets;
use App\Actions\Enrollment\TransitionQueueTicket;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\AcademicTermStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QueueTicket\IndexQueueTicketRequest;
use App\Http\Requests\Api\V1\QueueTicket\StoreQueueTicketRequest;
use App\Http\Requests\Api\V1\QueueTicket\UpdateQueueTicketRequest;
use App\Http\Resources\Api\V1\QueueTicketResource;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class QueueTicketController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(IndexQueueTicketRequest $request, ListQueueTickets $listQueueTickets): JsonResponse
    {
        $this->authenticatedUser($request);
        $this->authorize('viewAny', QueueTicket::class);

        $tickets = $listQueueTickets->execute($request->validated());

        $response = QueueTicketResource::collection($tickets)->response($request);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreQueueTicketRequest $request,
        ClaimQueueTicket $claimQueueTicket,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $enrollment = $this->resolveEnrollment($actor, $request->validated('student_number'));

        $this->authorize('claimQueueTicket', $enrollment);

        $ticket = $claimQueueTicket->execute($enrollment, $actor, $contextFactory->fromRequest($request));

        $response = QueueTicketResource::make($ticket)->response($request)->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateQueueTicketRequest $request,
        QueueTicket $queueTicket,
        TransitionQueueTicket $transitioner,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('update', QueueTicket::class);

        $ticket = $transitioner->execute(
            $queueTicket,
            $request->validated('action'),
            $actor,
            $contextFactory->fromRequest($request),
        );

        return $this->cachePrivateResponse(QueueTicketResource::make($ticket)->response($request));
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
     * Who to resolve is decided by whether `student_number` was supplied,
     * not by the actor's role: a Student omits it and gets their own
     * profile, while any role that supplies one (Accounting Staff at the
     * front desk, or anyone else) is looked up by that number. Whether the
     * caller is actually *allowed* to claim the resulting enrollment is
     * `EnrollmentPolicy::claimQueueTicket`'s job, not this method's — a
     * Registrar Staff supplying a real student number must still resolve a
     * real `Enrollment` so the Policy can reject it with 403, not a 422
     * that would mask the authorization boundary behind a validation error.
     * Accounting Staff alone must supply a number (their claim is always
     * on someone else's behalf); everyone else's omission just means "my
     * own enrollment", which may or may not exist.
     */
    private function resolveEnrollment(User $actor, ?string $studentNumber): Enrollment
    {
        if ($actor->role === UserRole::AccountingStaff && $studentNumber === null) {
            throw ValidationException::withMessages(['student_number' => 'A student number is required.']);
        }

        $student = $studentNumber !== null
            ? StudentProfile::query()->where('student_number', $studentNumber)->first()
            : StudentProfile::query()->where('user_id', $actor->id)->first();

        $term = AcademicTerm::query()->where('status', AcademicTermStatus::SemesterOngoing)->first();

        $enrollment = ($student === null || $term === null) ? null : Enrollment::query()
            ->where('student_id', $student->id)
            ->where('academic_term_id', $term->id)
            ->where('status', EnrollmentStatus::PendingPayment)
            ->first();

        if ($enrollment === null) {
            throw ValidationException::withMessages([
                'student_number' => 'No enrollment pending payment was found for this student.',
            ]);
        }

        return $enrollment;
    }

    /**
     * `private`: the payment queue is Accounting-internal operational
     * data — no shared cache may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
