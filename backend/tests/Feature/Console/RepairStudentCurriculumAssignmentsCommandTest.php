<?php

namespace Tests\Feature\Console;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class RepairStudentCurriculumAssignmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_previews_curriculum_repairs_without_changing_students(): void
    {
        [$profile, , $currentCurriculum] = $this->wronglyAssignedFourthYear();

        $exitCode = Artisan::call('curricula:repair-student-assignments', ['--dry-run' => true]);

        self::assertSame(0, $exitCode);
        $profile->refresh();
        self::assertSame($currentCurriculum->id, $profile->curriculum_id);
        self::assertStringContainsString('would_update=1', Artisan::output());
    }

    public function test_it_repairs_a_fourth_year_to_the_curriculum_for_their_entry_year(): void
    {
        [$profile, $oldCurriculum] = $this->wronglyAssignedFourthYear();

        $exitCode = Artisan::call('curricula:repair-student-assignments');

        self::assertSame(0, $exitCode);
        $profile->refresh();
        self::assertSame($oldCurriculum->id, $profile->curriculum_id);
        self::assertSame(2023, $profile->entry_year);
        self::assertStringContainsString('updated=1', Artisan::output());
    }

    /** @return array{StudentProfile, Curriculum, Curriculum} */
    private function wronglyAssignedFourthYear(): array
    {
        $program = Program::create([
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
            'status' => ProgramStatus::Active,
        ]);
        $oldCurriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSIT 2018 Curriculum',
            'effective_school_year' => '2018-2019',
            'effective_start_year' => 2018,
            'effective_end_year' => 2023,
            'status' => CurriculumStatus::Archived,
        ]);
        $currentCurriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSIT 2024 Curriculum',
            'effective_school_year' => '2024-2025',
            'effective_start_year' => 2024,
            'effective_end_year' => 2029,
            'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Fourth Year Student',
            'email' => 'fourth.year.curriculum@grc.test',
            'password' => 'password',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2023-08-00001',
            'program_id' => $program->id,
            'curriculum_id' => $currentCurriculum->id,
            'entry_year' => 2023,
            'year_level' => 4,
            'admission_status' => AdmissionStatus::Enrolled,
            'academic_standing' => AcademicStanding::Good,
        ]);

        return [$profile, $oldCurriculum, $currentCurriculum];
    }
}
