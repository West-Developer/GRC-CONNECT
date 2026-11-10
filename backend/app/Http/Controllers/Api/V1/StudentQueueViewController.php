<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Enrollment\BuildStudentQueueView;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StudentQueueViewResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRD §5.3 FR-FIN-006: the student's own read-only "where am I in the
 * queue" view. Gated entirely by the route's `role:student` middleware —
 * there is no per-record ownership dimension to check beyond "you are the
 * signed-in student", the same shape as the Dean's `stuck-enrollments`
 * single-action endpoint.
 */
final class StudentQueueViewController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function show(Request $request, BuildStudentQueueView $buildStudentQueueView): JsonResponse
    {
        $actor = $this->authenticatedUser($request);

        $view = $buildStudentQueueView->execute($actor);

        $response = (new StudentQueueViewResource($view))->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
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
}
