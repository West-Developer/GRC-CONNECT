<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Curriculum\ResolveCurrentCurriculumSubjectSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SubjectResource;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurrentCurriculumSubjectController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(Request $request, Program $program, ResolveCurrentCurriculumSubjectSource $resolver): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('createForProgram', [Curriculum::class, $program]);

        $source = $resolver->execute($program);
        $subjects = $source?->subjectPlacements->pluck('subject')->unique('id')->values() ?? collect();
        $response = SubjectResource::collection($subjects)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
