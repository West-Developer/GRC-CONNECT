<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organization\TransitionCollegeWorkflow;
use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcademicTerm\IndexAcademicTermWorkflowRequest;
use App\Http\Requests\Api\V1\AcademicTerm\UpdateAcademicTermWorkflowRequest;
use App\Http\Resources\Api\V1\AcademicTermWorkflowResource;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicTermWorkflowController extends Controller
{
    /** @throws AuthenticationException */
    public function index(IndexAcademicTermWorkflowRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', AcademicTermCollegeWorkflow::class);

        $query = AcademicTermCollegeWorkflow::query()
            ->where('academic_term_id', $request->validated('academic_term_id'));

        if ($user->role === UserRole::ProgramChair) {
            if ($user->college === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('college', $user->college->value);
            }
        }

        $workflows = $query->orderBy('college')->get();

        return $this->cachePrivateResponse(AcademicTermWorkflowResource::collection($workflows)->response($request));
    }

    /** @throws AuthenticationException */
    public function update(
        UpdateAcademicTermWorkflowRequest $request,
        AcademicTermCollegeWorkflow $workflow,
        TransitionCollegeWorkflow $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $workflow);

        $updated = $action->execute(
            $workflow,
            (string) $request->validated('action'),
            $user,
            $contextFactory->fromRequest($request),
        );

        return $this->cachePrivateResponse(AcademicTermWorkflowResource::make($updated)->response($request));
    }

    /** @throws AuthenticationException */
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
