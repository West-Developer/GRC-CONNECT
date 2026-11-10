<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\AssignSectionProfessor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Section\AssignSectionProfessorRequest;
use App\Http\Resources\Api\V1\SectionResource;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

/**
 * The Dean's way to change who teaches a section of their own college
 * (ADR 0033). It cannot touch anything else about the section.
 */
final class SectionProfessorController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function update(
        AssignSectionProfessorRequest $request,
        Section $section,
        AssignSectionProfessor $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('assignProfessor', $section);

        $professorId = $request->validated('professor_id');
        $section = $action->execute(
            $actor,
            $section,
            $professorId === null ? null : (int) $professorId,
            $request->validated('override_reason'),
            $contextFactory->fromRequest($request),
        );

        $response = SectionResource::make($section->load('professor:id,name'))->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
