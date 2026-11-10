<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\BuildEnrollmentBlockPool;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EnrollmentBlock\IndexEnrollmentBlockRequest;
use App\Http\Resources\Api\V1\EnrollmentBlockResource;
use App\Models\AcademicTerm;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class EnrollmentBlockController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(
        IndexEnrollmentBlockRequest $request,
        BuildEnrollmentBlockPool $buildEnrollmentBlockPool,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('view-eligible-subjects');

        $student = StudentProfile::query()->where('user_id', $actor->id)->firstOrFail();
        $term = AcademicTerm::query()->where('id', $request->validated('academic_term_id'))->firstOrFail();

        $blocks = $buildEnrollmentBlockPool->execute($student, $term);

        $response = EnrollmentBlockResource::collection($blocks)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
