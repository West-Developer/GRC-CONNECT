<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Academic\ListGraduates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Academic\IndexGraduatesRequest;
use App\Http\Resources\Api\V1\GraduateResource;
use App\Models\StudentProfile;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class GraduateController extends Controller
{
    public function __invoke(IndexGraduatesRequest $request, ListGraduates $listGraduates): AnonymousResourceCollection
    {
        Gate::authorize('view-graduates');

        $graduates = $listGraduates->execute($request->validated());

        return GraduateResource::collection($graduates)->additional([
            'summary' => [
                'total_graduates' => StudentProfile::query()->where('admission_status', 'graduated')->count(),
            ],
        ]);
    }
}
