<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Room\IndexRoomOccupancyRequest;
use App\Http\Resources\Api\V1\RoomOccupancyResource;
use App\Models\Section;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class RoomOccupancyController extends Controller
{
    /** @throws AuthenticationException */
    public function __invoke(IndexRoomOccupancyRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        abort_unless(
            $actor->role === UserRole::ProgramChair || $actor->role === UserRole::RegistrarHead,
            403,
            'Room occupancy is available only to Program Heads and the Registrar Head.',
        );

        $data = $request->validated();

        // Deliberately NOT `Section::scopeVisibleTo()` — a shared room's true
        // occupancy must include every college's bookings, or another
        // college's slot would render as falsely available on this room's
        // calendar. `RoomCatalogEntryController` stays college-scoped
        // because it lists which rooms a chair MAY schedule, not what is
        // already booked in one.
        $sections = Section::query()
            ->where('academic_term_id', $data['academic_term_id'])
            ->where('room', $data['room'])
            ->whereNotNull('schedule_days')
            ->with(['subject', 'professor', 'sectionPlan'])
            ->orderBy('id')
            ->get();

        $response = response()->json([
            'data' => $sections
                ->map(fn (Section $section): RoomOccupancyResource => new RoomOccupancyResource($section, $actor->college?->value))
                ->values(),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
