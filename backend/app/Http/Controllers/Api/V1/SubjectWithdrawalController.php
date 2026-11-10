<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\ListSubjectWithdrawals;
use App\Actions\Enrollment\WithdrawEnrolledSubjects;
use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubjectWithdrawal\StoreSubjectWithdrawalRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Registrar Head's subject withdrawal: see what a student can still be
 * withdrawn from and what was already withdrawn, and withdraw subjects with a
 * reason. The route group is `role:registrar_head`; the check below repeats it
 * as defense in depth.
 */
final class SubjectWithdrawalController extends Controller
{
    /**
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function index(Request $request, StudentProfile $studentProfile, ListSubjectWithdrawals $list): JsonResponse
    {
        $this->registrarHead($request);

        return $this->privateResponse(['data' => $list->execute($studentProfile)]);
    }

    /**
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function store(
        StoreSubjectWithdrawalRequest $request,
        StudentProfile $studentProfile,
        WithdrawEnrolledSubjects $withdraw,
        ListSubjectWithdrawals $list,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->registrarHead($request);

        $withdraw->execute(
            $studentProfile,
            array_map('intval', $request->validated('enrollment_subject_ids')),
            trim((string) $request->validated('reason')),
            $actor,
            $contextFactory->fromRequest($request),
        );

        return $this->privateResponse(['data' => $list->execute($studentProfile)]);
    }

    /**
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    private function registrarHead(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if ($user->role !== UserRole::RegistrarHead) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /**
     * `private`: it names a student's academic record.
     *
     * @param  array<string, mixed>  $payload
     */
    private function privateResponse(array $payload): JsonResponse
    {
        $response = new JsonResponse($payload);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
