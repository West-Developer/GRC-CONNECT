<?php

namespace Tests\Feature\Console\Commands;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedHistoricalHonorsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_historical_honors_for_completed_terms(): void
    {
        require_once base_path('app/Console/Commands/SeedHistoricalHonorsCommand.php');

        $term = AcademicTerm::create([
            'school_year' => '2025-2026',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterClosed,
        ]);

        $program = Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);

        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS 2025',
            'effective_school_year' => '2025-2026',
            'status' => CurriculumStatus::Active,
        ]);

        $professor = User::create([
            'name' => 'Prof',
            'email' => 'prof@grc.test',
            'password' => 'secret',
            'role' => UserRole::Faculty,
            'status' => UserStatus::Active,
        ]);

        // Create 6 subjects (18 units total >= 16 minimum)
        $sections = [];
        for ($s = 1; $s <= 6; $s++) {
            $subj = Subject::create([
                'code' => "CS10{$s}",
                'title' => "CS Course {$s}",
                'units' => 3,
                'status' => SubjectStatus::Active,
            ]);

            $sec = Section::create([
                'academic_term_id' => $term->id,
                'subject_id' => $subj->id,
                'section_code' => "CS10{$s}-1A",
                'professor_id' => $professor->id,
                'capacity' => 40,
                'status' => SectionStatus::Published,
            ]);

            $sections[] = ['subject' => $subj, 'section' => $sec];
        }

        // Create 20 students with mediocre grades (e.g. 2.50)
        for ($i = 1; $i <= 20; $i++) {
            $studentUser = User::create([
                'name' => "Student {$i}",
                'email' => "student{$i}@grc.test",
                'password' => 'secret',
                'role' => UserRole::Student,
                'status' => UserStatus::Active,
            ]);

            $student = StudentProfile::create([
                'user_id' => $studentUser->id,
                'student_number' => sprintf('S-2025-%04d', $i),
                'program_id' => $program->id,
                'curriculum_id' => $curriculum->id,
                'year_level' => 1,
                'admission_status' => AdmissionStatus::Admitted,
                'academic_standing' => AcademicStanding::Good,
                'is_demo_account' => false,
            ]);

            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'academic_term_id' => $term->id,
                'status' => EnrollmentStatus::Enrolled,
                'enrolled_at' => now(),
            ]);

            foreach ($sections as $item) {
                EnrollmentSubject::create([
                    'enrollment_id' => $enrollment->id,
                    'section_id' => $item['section']->id,
                    'status' => EnrollmentSubjectStatus::Enrolled,
                ]);

                AcademicGrade::create([
                    'student_id' => $student->id,
                    'subject_id' => $item['subject']->id,
                    'section_id' => $item['section']->id,
                    'academic_term_id' => $term->id,
                    'mark' => GradeMark::Satisfactory,
                    'final_grade' => 2.50,
                    'status' => GradeStatus::Locked,
                    'encoded_by' => $professor->id,
                    'submitted_at' => now(),
                    'locked_at' => now(),
                ]);
            }
        }

        $reportBefore = (new \App\Actions\Academic\BuildHonorsReport())->execute($term, [], 1, 100);
        $this->assertEquals(0, $reportBefore->total());

        // Run command
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand(new \App\Console\Commands\SeedHistoricalHonorsCommand());
        $this->artisan('honors:seed-historical', ['--term' => $term->id])->assertSuccessful();

        $reportAfter = (new \App\Actions\Academic\BuildHonorsReport())->execute($term, [], 1, 100);
        $this->assertGreaterThan(0, $reportAfter->total());

        foreach ($reportAfter->items() as $item) {
            $gwa = (float) $item['gwa'];
            $this->assertGreaterThanOrEqual(1.00, $gwa);
            $this->assertLessThanOrEqual(1.50, $gwa);
            $this->assertGreaterThanOrEqual(16, $item['gwa_units']);
        }
    }
}
