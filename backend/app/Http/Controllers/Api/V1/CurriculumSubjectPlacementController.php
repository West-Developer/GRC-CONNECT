<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Curriculum\AddCurriculumSubjectPlacement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Curriculum\StoreCurriculumSubjectPlacementRequest;
use App\Http\Resources\Api\V1\CurriculumResource;
use App\Models\Curriculum;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class CurriculumSubjectPlacementController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(
        StoreCurriculumSubjectPlacementRequest $request,
        Curriculum $curriculum,
        AddCurriculumSubjectPlacement $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('update', $curriculum);

        $curriculum = $action->execute(
            $user,
            $curriculum,
            $request->validated(),
            $contextFactory->fromRequest($request),
        );

        $response = CurriculumResource::make($curriculum)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
