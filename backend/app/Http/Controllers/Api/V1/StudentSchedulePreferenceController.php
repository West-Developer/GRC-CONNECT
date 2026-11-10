<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\SaveStudentSchedulePreference;
use App\Domain\Enrollment\PreferredTimeBlock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StudentSchedulePreference\UpdateStudentSchedulePreferenceRequest;
use App\Http\Resources\Api\V1\StudentSchedulePreferenceResource;
use App\Models\StudentProfile;
use App\Models\StudentSchedulePreference;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentSchedulePreferenceController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', StudentSchedulePreference::class);

        $student = StudentProfile::query()->where('user_id', $user->id)->firstOrFail();
        $preference = $this->resolvePreference($student);

        $this->authorize('view', $preference);

        return $this->cachePrivateResponse(StudentSchedulePreferenceResource::make($preference)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateStudentSchedulePreferenceRequest $request,
        SaveStudentSchedulePreference $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', StudentSchedulePreference::class);

        $student = StudentProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->authorize('update', $this->resolvePreference($student));

        $preference = $action->execute($student, $user, $request->validated(), $contextFactory->fromRequest($request));

        // PUT is an upsert with full-replace semantics — always 200, even
        // the first time a row is created, unlike a POST-style 201.
        // Laravel's ResourceResponse otherwise infers 201 from
        // wasRecentlyCreated.
        $response = StudentSchedulePreferenceResource::make($preference)->response($request);
        $response->setStatusCode(200);

        return $this->cachePrivateResponse($response);
    }

    /**
     * The caller's own row, or an unsaved default (matching the migration's
     * column defaults) when none exists yet — `student_id` is always
     * resolved from the authenticated user's own StudentProfile, never from
     * request input.
     */
    private function resolvePreference(StudentProfile $student): StudentSchedulePreference
    {
        return StudentSchedulePreference::query()->where('student_id', $student->id)->first()
            ?? new StudentSchedulePreference([
                'student_id' => $student->id,
                'preferred_days' => null,
                'preferred_time_block' => PreferredTimeBlock::Any->value,
                'preferred_modality' => null,
                'max_days_on_campus' => null,
                'avoid_early_first_class' => false,
                'notes' => null,
            ]);
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
     * `private`: this is one student's own record — no shared cache may
     * retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
