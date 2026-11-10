<?php

namespace Tests\Feature\Database;

use App\Domain\Organization\CollegeCode;
use App\Models\Subject;
use Database\Seeders\GrcSubjectCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class GrcSubjectCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_real_four_college_catalog(): void
    {
        $this->seed(GrcSubjectCatalogSeeder::class);

        $this->assertDatabaseCount('subjects', 409);
        $this->assertSame(103, Subject::where('college', CollegeCode::Ccs)->count());
        $this->assertSame(142, Subject::where('college', CollegeCode::Coe)->count());
        $this->assertSame(70, Subject::where('college', CollegeCode::Coa)->count());
        $this->assertSame(94, Subject::where('college', CollegeCode::Cbae)->count());
    }

    public function test_seeder_creates_no_college_outside_the_supported_four(): void
    {
        $this->seed(GrcSubjectCatalogSeeder::class);

        $this->assertSame(0, Subject::whereNotIn('college', ['ccs', 'coe', 'coa', 'cbae'])->count());
        $this->assertSame(0, Subject::whereRaw('LOWER(title) LIKE ?', ['%criminology%'])->count());
    }

    public function test_reseeding_updates_in_place_without_duplicates(): void
    {
        $this->seed(GrcSubjectCatalogSeeder::class);
        $subjectIds = Subject::orderBy('id')->pluck('id')->all();

        $this->seed(GrcSubjectCatalogSeeder::class);

        $this->assertSame(409, Subject::count());
        $this->assertSame($subjectIds, Subject::orderBy('id')->pluck('id')->all());
    }

    /**
     * `GenerateFacultyAssignmentRecommendations::assignConfiguredRoom()`
     * refuses to auto-assign a room at all when a subject's
     * `room_requirement` is null — this was previously left to
     * `PredictivePlanningInputSeeder`, whose own precondition (a term that
     * is neither `archived` nor `semester_closed`) can never be satisfied
     * by the real dataset, where every seeded term starts in one of those
     * two states until the Registrar opens the next one. Every subject
     * needs a real value from the moment the catalog itself is seeded.
     */
    public function test_every_subject_gets_a_room_requirement(): void
    {
        $this->seed(GrcSubjectCatalogSeeder::class);

        $this->assertSame(0, Subject::whereNull('room_requirement')->count());
        $this->assertGreaterThan(0, Subject::where('room_requirement', 'laboratory')->count());
        $this->assertGreaterThan(0, Subject::where('room_requirement', 'lecture')->count());
        $labSubject = Subject::whereRaw('UPPER(title) LIKE ?', ['%LAB%'])->first();
        if ($labSubject !== null) {
            $this->assertSame('laboratory', $labSubject->room_requirement);
        }
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

        app(GrcSubjectCatalogSeeder::class)->run();
    }
}
