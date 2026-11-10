<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class IdentityFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasColumns('users', [
            'id', 'name', 'email', 'password', 'role', 'status',
            'last_login_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_users_email_is_unique(): void
    {
        DB::table('users')->insert([
            'name' => 'First',
            'email' => 'unique@example.test',
            'password' => 'hash',
            'role' => 'student',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Second',
            'email' => 'unique@example.test',
            'password' => 'hash',
            'role' => 'student',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_programs_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('programs'));
        $this->assertTrue(Schema::hasColumns('programs', [
            'id', 'code', 'name', 'status', 'created_at', 'updated_at',
        ]));
    }

    public function test_programs_code_is_unique(): void
    {
        DB::table('programs')->insert([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('programs')->insert([
            'code' => 'BSCS',
            'name' => 'Duplicate',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_academic_terms_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('academic_terms'));
        $this->assertTrue(Schema::hasColumns('academic_terms', [
            'id', 'school_year', 'semester', 'starts_at', 'ends_at',
            'enrollment_opens_at', 'enrollment_closes_at', 'status',
            'created_at', 'updated_at',
        ]));
    }

    public function test_academic_terms_school_year_and_semester_combination_is_unique(): void
    {
        DB::table('academic_terms')->insert([
            'school_year' => '2026-2027',
            'semester' => 'first',
            'status' => 'upcoming',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('academic_terms')->insert([
            'school_year' => '2026-2027',
            'semester' => 'first',
            'status' => 'upcoming',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_personal_access_tokens_table_exists_for_sanctum(): void
    {
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertTrue(Schema::hasColumns('personal_access_tokens', [
            'id', 'tokenable_type', 'tokenable_id', 'name', 'token',
            'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_migrations_are_fully_reversible(): void
    {
        $this->artisan('migrate:rollback')->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('programs'));
        $this->assertFalse(Schema::hasTable('academic_terms'));
        $this->assertFalse(Schema::hasTable('personal_access_tokens'));

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('programs'));
        $this->assertTrue(Schema::hasTable('academic_terms'));
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
    }
}
