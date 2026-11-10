<?php

namespace Tests\Feature\Database;

use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use App\Models\User;
use Database\Seeders\CollegeProgramChairSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class CollegeProgramChairSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_one_program_chair_per_supported_college(): void
    {
        $this->seed(CollegeProgramChairSeeder::class);

        $this->assertDatabaseCount('users', 4);

        foreach (CollegeCode::cases() as $college) {
            $user = User::where('email', "chair.{$college->value}@grc.test")->sole();

            $this->assertSame(UserRole::ProgramChair, $user->role);
            $this->assertSame($college, $user->college);
        }
    }

    public function test_it_does_not_touch_the_existing_generic_program_chair(): void
    {
        User::create([
            'name' => 'Seed Program Chair',
            'email' => 'chair.seed@grc.test',
            'password' => 'password',
            'role' => UserRole::ProgramChair,
            'status' => 'active',
        ]);

        $this->seed(CollegeProgramChairSeeder::class);

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseHas('users', ['email' => 'chair.seed@grc.test', 'college' => null]);
    }

    public function test_reseeding_updates_in_place_without_duplicates(): void
    {
        $this->seed(CollegeProgramChairSeeder::class);
        $originalIds = User::orderBy('id')->pluck('id')->all();

        $this->seed(CollegeProgramChairSeeder::class);

        $this->assertSame(4, User::count());
        $this->assertSame($originalIds, User::orderBy('id')->pluck('id')->all());
    }

    public function test_seeder_refuses_to_run_outside_local_and_testing(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        app(CollegeProgramChairSeeder::class)->run();
    }
}
