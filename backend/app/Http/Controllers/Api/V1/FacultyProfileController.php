<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Faculty\BuildFacultyProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FacultyMember\ShowFacultyProfileRequest;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * A professor's teaching profile for the Registrar Head (stakeholder Doc 14).
 */
final class FacultyProfileController extends Controller
{
    public function __invoke(
        ShowFacultyProfileRequest $request,
        User $professor,
        BuildFacultyProfile $buildProfile,
    ): JsonResponse {
        $this->authorize('view-faculty-profile', $professor);

        $termId = $request->validated('academic_term_id');
        $term = $termId === null ? null : AcademicTerm::query()->findOrFail((int) $termId);

        $response = response()->json(['data' => $buildProfile->execute($professor, $term)]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
