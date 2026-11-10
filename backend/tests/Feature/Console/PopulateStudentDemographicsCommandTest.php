<?php

namespace Tests\Feature\Console;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\Gender;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsChedReportFixtures;
use Tests\TestCase;

final class PopulateStudentDemographicsCommandTest extends TestCase
{
    use BuildsChedReportFixtures;
    use RefreshDatabase;

    public function test_a_dry_run_lists_the_counts_and_writes_nothing(): void
    {
        $student = $this->chedStudent(['first_name' => 'Jose'], ['gender' => null]);

        $this->artisan('students:populate-demographics', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertNull($student->refresh()->gender);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_real_run_fills_the_students_and_audits_it_once(): void
    {
        $this->chedRegistrarHead();
        $student = $this->chedStudent(['first_name' => 'Jose'], ['gender' => null]);

        $this->artisan('students:populate-demographics')->assertExitCode(0);

        $this->assertSame(Gender::Male, $student->refresh()->gender);
        $audit = AuditLog::query()->where('action', AuditAction::STUDENT_DEMOGRAPHICS_POPULATED)->get();
        $this->assertCount(1, $audit);
        $this->assertSame(1, $audit->first()->after_values['students']);
    }

    public function test_it_still_works_when_there_is_no_registrar_head_to_credit_the_audit_to(): void
    {
        $student = $this->chedStudent(['first_name' => 'Maria'], ['gender' => null]);

        $this->artisan('students:populate-demographics')
            ->expectsOutputToContain('No Registrar Head')
            ->assertExitCode(0);

        $this->assertSame(Gender::Female, $student->refresh()->gender);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_wipe_takes_the_generated_values_back_and_audits_it(): void
    {
        $this->chedRegistrarHead();
        $student = $this->chedStudent(['first_name' => 'Jose'], ['gender' => null]);
        $this->artisan('students:populate-demographics')->assertExitCode(0);

        $this->artisan('students:populate-demographics', ['--wipe' => true])->assertExitCode(0);

        $student->refresh();
        $this->assertNull($student->gender);
        $this->assertNull($student->demographics_demo_fields);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::STUDENT_DEMOGRAPHICS_WIPED)->count());
    }
}
