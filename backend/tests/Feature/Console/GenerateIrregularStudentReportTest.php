<?php

namespace Tests\Feature\Console;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class GenerateIrregularStudentReportTest extends TestCase
{
    use RefreshDatabase;

    private string $rosterPath;

    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rosterPath = storage_path('framework/testing/Students-Profile-irregular-report-fixture.md');
        $this->outputPath = storage_path('framework/testing/Irregular-Students-report-output.md');
        File::ensureDirectoryExists(dirname($this->rosterPath));
    }

    private function seedRosterStudent(string $studentNumber, string $category): void
    {
        $program = Program::query()->where('code', 'BSA')->first() ?? Program::create([
            'code' => 'BSA',
            'name' => 'BS Accountancy',
            'college' => CollegeCode::Coa,
            'status' => ProgramStatus::Active,
        ]);

        $curriculum = Curriculum::query()->where('program_id', $program->id)->first() ?? Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSA Curriculum 2024-2029',
            'effective_school_year' => '2024-2029',
            'effective_start_year' => 2024,
            'effective_end_year' => 2029,
            'status' => CurriculumStatus::Active,
        ]);

        $user = User::create([
            'name' => 'Student '.$studentNumber,
            'email' => 's'.substr($studentNumber, -7).'@grc.test',
            'password' => 'password',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $studentNumber,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 3,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
            'enrollment_category' => $category,
            'enrollment_category_derived_at' => now(),
        ]);
    }

    private function writeRosterFixture(): void
    {
        $contents = <<<MD
        # Students Profile

        ## College of Accountancy

        ### Year 3

        #### ACC301

        | Student No. | Name | Email | Program | Section | Year | Category |
        |---|---|---|---|---|---|---|
        | 2024-06-00001 | Ana Reyes | s0600001@grc.test | BSA | ACC301 | 3 | Regular |
        | 2024-06-00002 | Ben Cruz | s0600002@grc.test | BSA | ACC301 | 3 | Regular |
        | 2024-06-00003 | Cielo Santos | s0600003@grc.test | BSA | ACC301 | 3 | Regular |

        MD;

        File::put($this->rosterPath, $contents);
    }

    public function test_it_lists_only_students_the_database_derived_as_irregular(): void
    {
        $this->writeRosterFixture();
        $this->seedRosterStudent('2024-06-00001', 'regular');
        $this->seedRosterStudent('2024-06-00002', 'irregular');
        $this->seedRosterStudent('2024-06-00003', 'regular');

        $this->artisan('students:generate-irregular-report', [
            '--roster-path' => $this->rosterPath,
            '--path' => $this->outputPath,
        ])->assertExitCode(0);

        $contents = File::get($this->outputPath);
        $this->assertStringContainsString('Ben Cruz', $contents);
        $this->assertStringNotContainsString('Ana Reyes', $contents);
        $this->assertStringNotContainsString('Cielo Santos', $contents);
        $this->assertStringContainsString('**Total irregular students:** 1', $contents);
    }

    public function test_it_throws_when_the_roster_has_not_been_seeded_into_the_database(): void
    {
        $this->writeRosterFixture();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/StudentRosterSeeder/');

        $this->artisan('students:generate-irregular-report', [
            '--roster-path' => $this->rosterPath,
            '--path' => $this->outputPath,
        ]);
    }

    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        $this->writeRosterFixture();
        app()->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->artisan('students:generate-irregular-report', [
            '--roster-path' => $this->rosterPath,
            '--path' => $this->outputPath,
        ]);
    }
}
