<?php

namespace Tests\Feature\Database;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\User;
use Database\Seeders\RoleUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

final class RoleUserSeederTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_PASSWORD = 'password';

    public function test_it_seeds_exactly_one_active_human_user_for_every_human_role(): void
    {
        $this->seed(RoleUserSeeder::class);

        $this->assertSame(10, User::count());

        foreach (UserRole::seedableCases() as $role) {
            $user = User::where('role', $role->value)->first();

            $this->assertNotNull($user, "Missing seeded user for role {$role->value}.");
            $this->assertSame(UserStatus::Active, $user->status);
        }

        $this->assertDatabaseMissing('users', ['role' => UserRole::QueueKiosk->value]);
        $this->assertDatabaseMissing('users', ['role' => UserRole::SuperAdmin->value]);
    }

    public function test_it_never_seeds_a_super_admin(): void
    {
        $this->seed(RoleUserSeeder::class);
        $this->assertDatabaseMissing('users', ['role' => UserRole::SuperAdmin->value]);
    }

    public function test_seeded_emails_are_unique_and_use_the_reserved_test_domain(): void
    {
        $this->seed(RoleUserSeeder::class);

        $emails = User::pluck('email');

        $this->assertCount(10, $emails);
        $this->assertCount(10, $emails->unique());

        foreach ($emails as $email) {
            $this->assertStringEndsWith('@grc.test', $email);
        }
    }

    public function test_the_seed_dean_is_scoped_to_ccs_for_local_curriculum_reviews(): void
    {
        $this->seed(RoleUserSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'dean.seed@grc.test',
            'role' => UserRole::Dean->value,
            'college' => CollegeCode::Ccs->value,
        ]);
    }

    public function test_it_seeds_the_it_control_identity(): void
    {
        $this->seed(RoleUserSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'it.control@grc.test',
            'role' => 'it_admin',
            'status' => UserStatus::Active->value,
        ]);
    }

    public function test_seeded_passwords_are_hashed_not_stored_in_plain_text(): void
    {
        $this->seed(RoleUserSeeder::class);

        $user = User::firstOrFail();

        $this->assertNotSame(self::SEED_PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::SEED_PASSWORD, $user->password));
    }

    public function test_reseeding_updates_in_place_without_creating_duplicates(): void
    {
        $this->seed(RoleUserSeeder::class);
        $originalIds = User::orderBy('id')->pluck('id')->all();

        $this->seed(RoleUserSeeder::class);

        $this->assertSame(10, User::count());
        $this->assertSame($originalIds, User::orderBy('id')->pluck('id')->all());
    }

    public function test_it_does_not_delete_unrelated_users(): void
    {
        $unrelated = User::create([
            'name' => 'Pre-existing Person',
            'email' => 'unrelated@example.test',
            'password' => 'irrelevant',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        $this->seed(RoleUserSeeder::class);

        $this->assertDatabaseHas('users', ['id' => $unrelated->id]);
        $this->assertSame(11, User::count());
    }

    public function test_it_seeds_no_programs_or_academic_terms(): void
    {
        $this->seed(RoleUserSeeder::class);

        $this->assertDatabaseCount('programs', 0);
        $this->assertDatabaseCount('academic_terms', 0);
    }

    public function test_it_uses_the_shared_development_password_without_environment_configuration(): void
    {
        putenv('GRC_SEED_PASSWORD');

        $this->seed(RoleUserSeeder::class);

        foreach (User::all() as $user) {
            $this->assertTrue(
                Hash::check(self::SEED_PASSWORD, $user->password),
                "Seeded user {$user->email} does not use the shared development password.",
            );
        }
    }

    /**
     * Invoked directly rather than through `db:seed`, because the artisan
     * command's own production confirmation prompt would intercept the call
     * before the seeder runs. This asserts the seeder is safe even when
     * chained programmatically from another seeder.
     */
    public function test_it_refuses_to_run_outside_local_and_testing_environments(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        app(RoleUserSeeder::class)->run();
    }

    public function test_it_seeds_nothing_when_it_refuses_to_run(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            app(RoleUserSeeder::class)->run();
        } catch (RuntimeException) {
            // Expected; asserted in the preceding test.
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_legacy_environment_override_cannot_change_the_shared_password(): void
    {
        putenv('GRC_SEED_PASSWORD=legacy-local-password');

        try {
            $this->seed(RoleUserSeeder::class);
        } finally {
            putenv('GRC_SEED_PASSWORD');
        }

        foreach (User::all() as $user) {
            $this->assertTrue(Hash::check(self::SEED_PASSWORD, $user->password));
            $this->assertFalse(Hash::check('legacy-local-password', $user->password));
        }
    }
}
