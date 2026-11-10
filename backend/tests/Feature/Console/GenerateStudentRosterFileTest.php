<?php

namespace Tests\Feature\Console;

use RuntimeException;
use Tests\TestCase;

final class GenerateStudentRosterFileTest extends TestCase
{
    public function test_it_writes_a_roster_file_with_summary_tables_and_3210_rows(): void
    {
        $path = storage_path('framework/testing/Students-Profile.md');

        $this->artisan('students:generate-roster-file', ['--path' => $path])->assertExitCode(0);

        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $this->assertStringContainsString('| **Kabuuan** |  **107** |          **3,210** |', $contents);
        $this->assertSame(3210, substr_count($contents, '@grc.com'));
        $this->assertStringContainsString('| 2023-06-01001 |', $contents);
    }

    public function test_check_mode_reports_drift_without_writing(): void
    {
        $path = storage_path('framework/testing/Students-Profile.md');
        file_put_contents($path, "# stale\n");

        $this->artisan('students:generate-roster-file', ['--path' => $path, '--check' => true])->assertExitCode(1);
        $this->assertSame("# stale\n", file_get_contents($path));
    }

    public function test_the_committed_roster_file_is_in_sync_with_the_generator(): void
    {
        $this->artisan('students:generate-roster-file', ['--check' => true])->assertExitCode(0);
    }

    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->expectException(RuntimeException::class);
        $this->artisan('students:generate-roster-file');
    }
}
