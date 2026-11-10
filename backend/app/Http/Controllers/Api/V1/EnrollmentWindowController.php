<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organization\BuildEnrollmentScheduleSummary;
use App\Actions\Organization\SaveEnrollmentSchedule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcademicTerm\UpdateEnrollmentScheduleRequest;
use App\Http\Resources\Api\V1\EnrollmentScheduleResource;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EnrollmentWindowController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(
        Request $request,
        AcademicTerm $academicTerm,
        BuildEnrollmentScheduleSummary $buildEnrollmentScheduleSummary,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('view', $academicTerm);

        $summary = $buildEnrollmentScheduleSummary->execute($academicTerm, $user);

        return $this->cachePrivateResponse(
            EnrollmentScheduleResource::make($summary)->response($request),
        );
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateEnrollmentScheduleRequest $request,
        AcademicTerm $academicTerm,
        SaveEnrollmentSchedule $saveEnrollmentSchedule,
        BuildEnrollmentScheduleSummary $buildEnrollmentScheduleSummary,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $academicTerm);

        $schedule = [
            'enrollment_opens_at' => (string) $request->validated('enrollment_opens_at'),
            'enrollment_closes_at' => (string) $request->validated('enrollment_closes_at'),
            'add_drop_opens_at' => $request->validated('add_drop_opens_at'),
            'add_drop_closes_at' => $request->validated('add_drop_closes_at'),
            'windows' => $request->windows(),
        ];

        // Only a save that names the platform changes it (null clears it); one
        // that leaves it out keeps whatever the Registrar Head set before.
        if ($request->has('enrollment_platform')) {
            $schedule['enrollment_platform'] = $request->validated('enrollment_platform');
        }

        $term = $saveEnrollmentSchedule->execute(
            $academicTerm,
            $schedule,
            $user,
            $contextFactory->fromRequest($request),
        );

        $summary = $buildEnrollmentScheduleSummary->execute($term, $user);

        return $this->cachePrivateResponse(
            EnrollmentScheduleResource::make($summary)->response($request),
        );
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
