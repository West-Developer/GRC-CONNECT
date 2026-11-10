<?php

namespace Tests\Feature\Console;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\SectionDemandObservation;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeriveSectionDemandObservationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_derives_observations_for_every_term_by_default(): void
    {
        $this->seedOneRealEnrollment();

        $this->artisan('analytics:derive-demand-observations')->assertExitCode(0);

        $this->assertSame(1, SectionDemandObservation::where('source', 'derived_from_enrollments')->count());
    }

    public function test_the_term_option_restricts_aggregation_to_that_term(): void
    {
        $termId = $this->seedOneRealEnrollment();
        $otherTerm = AcademicTerm::create(['school_year' => '2030-2031', 'semester' => '1st', 'status' => 'archived']);

        $this->artisan('analytics:derive-demand-observations', ['--term' => $otherTerm->id])->assertExitCode(0);

        $this->assertSame(0, SectionDemandObservation::where('source', 'derived_from_enrollments')->count());

        $this->artisan('analytics:derive-demand-observations', ['--term' => $termId])->assertExitCode(0);

        $this->assertSame(1, SectionDemandObservation::where('source', 'derived_from_enrollments')->count());
    }

    public function test_an_unknown_term_id_fails_without_crashing(): void
    {
        $this->artisan('analytics:derive-demand-observations', ['--term' => 999999])->assertExitCode(1);
    }

    /** @return int the academic_term_id the seeded enrollment belongs to */
    private function seedOneRealEnrollment(): int
    {
        $term = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '1st', 'status' => 'archived']);
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active]);
        $subject = Subject::create(['code' => 'IT301', 'college' => CollegeCode::Ccs, 'title' => 'Systems Analysis', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'year_level' => 3, 'semester' => '1st', 'is_required' => true]);

        $user = User::create(['name' => 'Student One', 'email' => 'student.one@grc.test', 'password' => 'password', 'role' => 'student', 'status' => 'active']);
        $student = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2027-06-00001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2024,
            'year_level' => 3,
            'admission_status' => 'admitted',
            'academic_standing' => 'good',
        ]);

        // The plan carries the authoritative "what year level was this
        // section actually offered at" value (year_level 1 here, distinct
        // from the student's own year_level 3 above) — see
        // DeriveSectionDemandObservations's docblock. Every section
        // DeriveSectionDemandObservations aggregates must have one; a
        // section with no section_plan_id is excluded from its query.
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'college' => CollegeCode::Ccs->value,
            'year_level' => 1,
            'section_count' => 1,
            'status' => 'submitted',
        ]);

        $section = Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $subject->id,
            'section_code' => 'IT301A',
            'capacity' => 40,
            'capacity_source' => 'plan',
            'status' => 'closed',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => 'enrolled',
            'total_units' => 3,
        ]);

        EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
            'status' => 'enrolled',
        ]);

        return $term->id;
    }
}
