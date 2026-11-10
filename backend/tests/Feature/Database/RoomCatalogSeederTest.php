<?php

namespace Tests\Feature\Database;

use App\Models\RoomCatalogEntry;
use Database\Seeders\RoomCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class RoomCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `GenerateFacultyAssignmentRecommendations::assignConfiguredRoom()`
     * only matches against rooms with a non-null `capacity` and a
     * `room_type` equal to the subject's `room_requirement` — every
     * seeded room previously had both left null, so no room could ever be
     * auto-assigned regardless of subject metadata.
     */
    public function test_every_room_gets_a_capacity_and_type(): void
    {
        $this->seed(RoomCatalogSeeder::class);

        $this->assertSame(0, RoomCatalogEntry::whereNull('capacity')->count());
        $this->assertSame(0, RoomCatalogEntry::whereNull('room_type')->count());
        $this->assertGreaterThan(0, RoomCatalogEntry::where('room_type', 'laboratory')->count());
        $this->assertGreaterThan(0, RoomCatalogEntry::where('room_type', 'lecture')->count());
        $labRoom = RoomCatalogEntry::whereRaw('UPPER(name) LIKE ?', ['%LAB%'])->first();
        $this->assertNotNull($labRoom);
        $this->assertSame('laboratory', $labRoom->room_type);
    }

    public function test_reseeding_updates_in_place_without_duplicates(): void
    {
        $this->seed(RoomCatalogSeeder::class);
        $ids = RoomCatalogEntry::orderBy('id')->pluck('id')->all();

        $this->seed(RoomCatalogSeeder::class);

        $this->assertSame($ids, RoomCatalogEntry::orderBy('id')->pluck('id')->all());
    }

    /**
     * Invoked directly rather than through `db:seed`, because the artisan
     * command's own production confirmation prompt would intercept the call
     * before the seeder runs — see RoleUserSeederTest for the same pattern.
     */
    public function test_seeder_refuses_to_run_outside_local_and_testing(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        app(RoomCatalogSeeder::class)->run();
    }
}
