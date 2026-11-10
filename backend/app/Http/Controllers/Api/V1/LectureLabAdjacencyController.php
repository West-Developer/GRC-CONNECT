<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\ListLectureLabAdjacencyViolations;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Program Head's lecture/lab adjacency warnings for one term (stakeholder
 * Doc 14). Route-gated to the Program Head role; the sections listed are only
 * those the Program Head may see (`Section::visibleTo`, own college).
 */
final class LectureLabAdjacencyController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(Request $request, AcademicTerm $academicTerm, ListLectureLabAdjacencyViolations $violations): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        abort_unless($user->college !== null, 422, 'A college-scoped Program Head is required.');

        $response = response()->json(['data' => $violations->execute($academicTerm, $user)]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
