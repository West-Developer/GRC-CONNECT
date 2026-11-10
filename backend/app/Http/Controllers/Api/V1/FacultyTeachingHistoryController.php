<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FacultyTeachingHistoryResource;
use App\Models\FacultyTeachingHistory;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FacultyTeachingHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $history = FacultyTeachingHistory::query()
            ->with(['subject', 'curriculum'])
            ->where('professor_id', $user->id)
            ->orderBy('curriculum_id')->orderBy('semester')->orderByDesc('evidence_count')
            ->get();
        $response = FacultyTeachingHistoryResource::collection($history)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
