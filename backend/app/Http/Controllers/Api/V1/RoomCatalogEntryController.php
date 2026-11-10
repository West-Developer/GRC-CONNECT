<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RoomCatalogEntryResource;
use App\Models\RoomCatalogEntry;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoomCatalogEntryController extends Controller
{
    /** @throws AuthenticationException */
    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        abort_unless(
            $actor->role === UserRole::ProgramChair || $actor->role === UserRole::RegistrarHead,
            403,
            'Rooms are available only to Program Heads and the Registrar Head.',
        );

        $rooms = RoomCatalogEntry::query()
            ->when(
                $actor->role === UserRole::ProgramChair,
                fn ($query) => $query->where('college', $actor->college?->value),
            )
            ->when(
                $actor->role === UserRole::RegistrarHead,
                fn ($query) => $query->selectRaw('MIN(id) as id, name, MIN(capacity) as capacity, MIN(room_type) as room_type, MIN(college) as college')->groupBy('name'),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $response = RoomCatalogEntryResource::collection($rooms)->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
