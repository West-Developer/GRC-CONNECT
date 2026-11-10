<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BuildStudentAccountBalance;
use App\Actions\Billing\RecordAccountPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StudentAccount\StoreAccountPaymentRequest;
use App\Http\Resources\Api\V1\StudentAccountResource;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentAccountController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function showOwn(Request $request, BuildStudentAccountBalance $buildStudentAccountBalance): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $student = StudentProfile::query()->where('user_id', $actor->id)->with('user')->firstOrFail();
        $this->authorize('viewAccount', $student);

        return $this->privateResponse(new StudentAccountResource($student, $buildStudentAccountBalance->execute($student)), $request);
    }

    /**
     * @throws AuthenticationException
     */
    public function show(
        Request $request,
        StudentProfile $student,
        BuildStudentAccountBalance $buildStudentAccountBalance,
    ): JsonResponse {
        $this->authenticatedUser($request);
        $this->authorize('viewAccount', $student);
        $student->load('user');

        return $this->privateResponse(new StudentAccountResource($student, $buildStudentAccountBalance->execute($student)), $request);
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreAccountPaymentRequest $request,
        StudentProfile $student,
        RecordAccountPayment $recordAccountPayment,
        BuildStudentAccountBalance $buildStudentAccountBalance,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);
        $this->authorize('recordAccountPayment', $student);
        if ($request->filled('financial_status')) {
            $student->update([
                'financial_status' => $request->validated('financial_status'),
            ]);
        }

        $rawAmount = $request->validated('amount');
        $amount = $rawAmount !== null ? (string) $rawAmount : '0.00';

        if (bccomp($amount, '0.00', 2) === 1) {
            $balance = $recordAccountPayment->execute(
                $student,
                $amount,
                $actor,
                $contextFactory->fromRequest($request),
            );
        } else {
            $balance = $buildStudentAccountBalance->execute($student);
        }

        $response = (new StudentAccountResource($student, $balance))->response($request);
        $response->setStatusCode(201);

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

    private function privateResponse(StudentAccountResource $resource, Request $request): JsonResponse
    {
        return $this->cachePrivateResponse($resource->response($request));
    }

    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
