<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StudentDemoAccountMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_profiles_can_exclude_local_demo_accounts_from_analytics(): void
    {
        $this->assertTrue(Schema::hasColumn('student_profiles', 'is_demo_account'));
    }
}
