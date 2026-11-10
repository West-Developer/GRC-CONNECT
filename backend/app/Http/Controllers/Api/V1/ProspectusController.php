<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Academic\BuildStudentProspectus;
use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcademicRecord\ShowProspectusRequest;
use App\Http\Resources\Api\V1\ProspectusResource;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class ProspectusController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(ShowProspectusRequest $request, BuildStudentProspectus $builder): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $student = $this->resolveStudent($request, $actor);

        $this->authorize('view-academic-record', $student);

        $prospectus = $builder->execute($student);

        $response = ProspectusResource::make($prospectus)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @throws AuthorizationException
     */
    private function resolveStudent(ShowProspectusRequest $request, User $actor): StudentProfile
    {
        $studentId = $request->validated('student_id');
        $studentNumber = $request->validated('student_number');

        if ($studentId !== null) {
            return StudentProfile::query()->where('id', $studentId)->firstOrFail();
        }

        if ($studentNumber !== null) {
            $clean = trim((string) $studentNumber);
            $unhyphenated = str_replace('-', '', $clean);
            $hyphenated = preg_match('/^\d{11}$/', $clean)
                ? substr($clean, 0, 4).'-'.substr($clean, 4, 2).'-'.substr($clean, 6, 5)
                : $clean;

            return StudentProfile::query()
                ->where('student_number', $clean)
                ->orWhere('student_number', $hyphenated)
                ->orWhereRaw("REPLACE(student_number, '-', '') = ?", [$unhyphenated])
                ->firstOrFail();
        }

        if ($actor->role === UserRole::Student) {
            return StudentProfile::query()->where('user_id', $actor->id)->firstOrFail();
        }

        // A non-student actor omitting student_id has no "own" record to
        // fall back to and nothing for AcademicRecordPolicy to evaluate
        // against — this is unauthorized, not a 404, since no student_id
        // this role could supply would ever resolve to their own profile.
        throw new AuthorizationException('A student_id or student_number is required for this role.');
    }
}
