<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CapacitySource;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AutoAssignSectionScheduleEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenForNewUser(UserRole $role, string $email, ?CollegeCode $college = null): string
    {
        $user = User::create(['name' => 'Test User', 'email' => $email, 'password' => self::PASSWORD, 'role' => $role, 'college' => $college, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);

        return (string) $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.token');
    }

    public function test_a_program_chair_can_auto_assign_section_schedules(): void
    {
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);
        $subject = Subject::create(['code' => 'ITC', 'college' => CollegeCode::Ccs, 'title' => 'ITC', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true, 'reference_day' => 'Tue', 'reference_professor_name' => 'MR. MACINAS']);
        $plan = AcademicTermSectionPlan::create(['academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => 'ccs', 'year_level' => 1, 'section_count' => 1, 'students_per_block' => 40, 'status' => SectionPlanStatus::Draft]);
        $section = Section::create(['academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id, 'section_code' => 'IT101', 'capacity' => 40, 'capacity_source' => CapacitySource::Plan, 'is_block_exclusive' => true, 'status' => SectionStatus::Planned]);
        $token = $this->tokenForNewUser(UserRole::ProgramChair, 'chair.autoassign@grc.test', CollegeCode::Ccs);

        $response = $this->withToken($token)->postJson("/api/v1/academic-terms/{$term->id}/section-plan/auto-assign", ['curriculum_id' => $curriculum->id]);

        $response->assertOk();
        $this->assertSame('Tue', $section->refresh()->schedule_days);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::SECTION_PLAN_AUTO_ASSIGNED)->count());
    }

    /**
     * The endpoint sits behind `role:program_chair`, which says *whether* the
     * caller is a Chair but not *whose* sections they may bulk-write. Without
     * an ownership check, any Chair could fill in another college's schedule.
     */
    public function test_a_program_chair_cannot_auto_assign_another_colleges_curriculum(): void
    {
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);
        $subject = Subject::create(['code' => 'ITC', 'college' => CollegeCode::Ccs, 'title' => 'ITC', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true, 'reference_day' => 'Tue', 'reference_professor_name' => 'MR. MACINAS']);
        $plan = AcademicTermSectionPlan::create(['academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => 'ccs', 'year_level' => 1, 'section_count' => 1, 'students_per_block' => 40, 'status' => SectionPlanStatus::Draft]);
        $section = Section::create(['academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id, 'section_code' => 'IT101', 'capacity' => 40, 'capacity_source' => CapacitySource::Plan, 'is_block_exclusive' => true, 'status' => SectionStatus::Planned]);
        $token = $this->tokenForNewUser(UserRole::ProgramChair, 'chair.coe.autoassign@grc.test', CollegeCode::Coe);

        $response = $this->withToken($token)->postJson("/api/v1/academic-terms/{$term->id}/section-plan/auto-assign", ['curriculum_id' => $curriculum->id]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('curriculum_id', $response->json('error.errors'));
        $section->refresh();
        $this->assertNull($section->schedule_days);
        $this->assertNull($section->professor_id);
        $this->assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_a_non_program_chair_role_cannot_auto_assign(): void
    {
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $token = $this->tokenForNewUser(UserRole::Student, 'student.autoassign@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/academic-terms/{$term->id}/section-plan/auto-assign", ['curriculum_id' => 1]);

        $response->assertForbidden();
    }
}
