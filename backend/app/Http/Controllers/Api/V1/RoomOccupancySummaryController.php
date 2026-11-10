<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\UserRole;
use App\Domain\Scheduling\ScheduleDayParser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Room\IndexRoomOccupancySummaryRequest;
use App\Models\Section;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

/**
 * Which rooms are in use this term and on which days, in one query, so the
 * Rooms screen can show "Empty" or "N classes" on every room before anyone
 * opens it (stakeholder Doc 14). Counts only; who teaches what stays in the
 * per-room occupancy endpoint.
 */
final class RoomOccupancySummaryController extends Controller
{
    /** @throws AuthenticationException */
    public function __invoke(IndexRoomOccupancySummaryRequest $request, ScheduleDayParser $dayParser): JsonResponse
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

        // Like the per-room endpoint, deliberately every college's bookings: a
        // shared room is only "Empty" if nobody at all has a class in it.
        $rows = Section::query()
            ->where('academic_term_id', $request->validated('academic_term_id'))
            ->whereNotNull('room')
            ->where('room', '!=', '')
            ->whereNotNull('schedule_days')
            ->get(['room', 'schedule_days']);

        /** @var array<string, array{room: string, classes_count: int, days: array<int, true>}> $rooms */
        $rooms = [];
        foreach ($rows as $row) {
            $room = (string) $row->room;
            $rooms[$room] ??= ['room' => $room, 'classes_count' => 0, 'days' => []];
            $rooms[$room]['classes_count']++;
            foreach ($dayParser->parse($row->schedule_days) as $day) {
                $rooms[$room]['days'][$day] = true;
            }
        }

        $data = array_map(function (array $room): array {
            $days = array_keys($room['days']);
            sort($days);

            return ['room' => $room['room'], 'classes_count' => $room['classes_count'], 'days' => $days];
        }, array_values($rooms));
        usort($data, fn (array $a, array $b): int => strnatcasecmp($a['room'], $b['room']));

        $response = response()->json(['data' => $data]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
