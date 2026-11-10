<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\BuildEligibleSubjectPool;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EligibleSubject\IndexEligibleSubjectRequest;
use App\Http\Resources\Api\V1\EligibleSubjectResource;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class EligibleSubjectController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(
        IndexEligibleSubjectRequest $request,
        BuildEligibleSubjectPool $buildEligibleSubjectPool,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('view-eligible-subjects');

        $student = StudentProfile::query()->where('user_id', $actor->id)->firstOrFail();
        $term = AcademicTerm::query()->where('id', $request->validated('academic_term_id'))->firstOrFail();

        $enrollmentId = $request->validated('enrollment_id');
        if ($enrollmentId !== null) {
            $enrollment = Enrollment::query()->where('student_id', $student->id)->whereKey($enrollmentId)->firstOrFail();
            $this->authorize('reviseSubjects', $enrollment);
            if ($enrollment->academic_term_id !== $term->id) {
                throw ValidationException::withMessages(['academic_term_id' => 'Choose the same term as this enrollment.']);
            }
        }

        $entries = $buildEligibleSubjectPool->execute($student, $term, $enrollmentId === null ? null : (int) $enrollmentId);

        $response = EligibleSubjectResource::collection($entries)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
