<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Academic\BuildAcademicRecord;
use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcademicRecord\ShowAcademicRecordRequest;
use App\Http\Resources\Api\V1\AcademicRecordResource;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class AcademicRecordController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(ShowAcademicRecordRequest $request, BuildAcademicRecord $builder): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $student = $this->resolveStudent($request, $actor);

        $this->authorize('view-academic-record', $student);

        $record = $builder->execute($student);

        $response = AcademicRecordResource::make($record)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @throws AuthorizationException
     */
    private function resolveStudent(ShowAcademicRecordRequest $request, User $actor): StudentProfile
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

        // See the identical guard in ProspectusController::resolveStudent().
        throw new AuthorizationException('A student_id or student_number is required for this role.');
    }
}
