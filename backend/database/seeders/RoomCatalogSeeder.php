<?php

namespace Database\Seeders;

use App\Domain\Organization\RoomCatalog;
use App\Models\RoomCatalogEntry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Local/test-only college-scoped room options for manual schedule assignment. */
final class RoomCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            foreach (RoomCatalog::all() as $room) {
                // Without capacity/room_type, GenerateFacultyAssignmentRecommendations's
                // room auto-assignment can never match any room at all — see
                // PredictivePlanningInputSeeder for the same defaults this
                // mirrors, scoped there to CCS only and to a precondition
                // the real dataset's term timeline can never satisfy.
                RoomCatalogEntry::updateOrCreate(
                    ['name' => $room['name'], 'college' => $room['college']->value],
                    [
                        'capacity' => 45,
                        'room_type' => str_contains(strtoupper($room['name']), 'LAB') ? 'laboratory' : 'lecture',
                    ],
                );
            }
        });
    }

    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('RoomCatalogSeeder may only run locally or in testing.');
        }
    }
}
