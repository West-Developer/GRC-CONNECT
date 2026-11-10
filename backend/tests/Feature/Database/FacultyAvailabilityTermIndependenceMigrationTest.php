<?php

namespace Tests\Feature\Database;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class FacultyAvailabilityTermIndependenceMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    public function test_it_aborts_before_mutation_when_non_sunday_slots_would_collide(): void
    {
        $this->rollbackThrough('2026_08_10_000001_make_faculty_availabilities_term_independent');

        $professor = User::create([
            'name' => 'Collision Test Faculty',
            'email' => 'migration.collision@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::Faculty,
            'status' => UserStatus::Active,
        ]);
        $firstTerm = AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::Archived,
        ]);
        $secondTerm = AcademicTerm::create([
            'school_year' => '2027-2028',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $timestamps = ['created_at' => now(), 'updated_at' => now()];

        DB::table('faculty_availabilities')->insert([
            [
                'professor_id' => $professor->id,
                'academic_term_id' => $firstTerm->id,
                'day_of_week' => 2,
                'starts_at_time' => '09:00:00',
                'ends_at_time' => '10:00:00',
                'origin' => 'declared',
            ] + $timestamps,
            [
                'professor_id' => $professor->id,
                'academic_term_id' => $secondTerm->id,
                'day_of_week' => 2,
                'starts_at_time' => '09:00:00',
                'ends_at_time' => '11:00:00',
                'origin' => 'declared',
            ] + $timestamps,
            [
                'professor_id' => $professor->id,
                'academic_term_id' => $secondTerm->id,
                'day_of_week' => 7,
                'starts_at_time' => '12:00:00',
                'ends_at_time' => '13:00:00',
                'origin' => 'declared',
            ] + $timestamps,
        ]);

        $migration = require database_path('migrations/2026_08_10_000001_make_faculty_availabilities_term_independent.php');

        try {
            $migration->up();
            self::fail('The migration did not abort for a term-independent slot collision.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                "Faculty availability migration aborted: term-independent slot collision for professor_id={$professor->id}, day_of_week=2, starts_at_time=09:00:00 (2 rows).",
                $exception->getMessage(),
            );
        }

        self::assertTrue(Schema::hasColumn('faculty_availabilities', 'academic_term_id'));
        self::assertSame(3, DB::table('faculty_availabilities')->count());
        $this->assertDatabaseHas('faculty_availabilities', [
            'professor_id' => $professor->id,
            'day_of_week' => 7,
            'starts_at_time' => '12:00:00',
        ]);
    }
}
