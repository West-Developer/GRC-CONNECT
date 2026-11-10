<?php

namespace Tests\Feature\Policies;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\User;
use App\Policies\CurriculumPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CurriculumPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, ?CollegeCode $college = null, ?string $email = null): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $email ?? $role->value.'@grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'college' => $college,
            'status' => UserStatus::Active,
        ]);
    }

    private function makeCurriculum(CurriculumStatus $status): Curriculum
    {
        $program = Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);

        return Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => $status,
        ]);
    }

    public function test_every_role_may_view_any(): void
    {
        $policy = new CurriculumPolicy;

        self::assertTrue($policy->viewAny($this->makeUser(UserRole::Faculty)));
        self::assertTrue($policy->viewAny($this->makeUser(UserRole::RegistrarHead)));
    }

    public function test_a_learner_scoped_role_cannot_view_a_draft_curriculum_by_direct_id(): void
    {
        $policy = new CurriculumPolicy;
        $faculty = $this->makeUser(UserRole::Faculty);
        $draft = $this->makeCurriculum(CurriculumStatus::Draft);

        self::assertFalse($policy->view($faculty, $draft));
    }

    public function test_a_planning_role_can_view_a_draft_curriculum_by_direct_id(): void
    {
        $policy = new CurriculumPolicy;
        $dean = $this->makeUser(UserRole::Dean);
        $draft = $this->makeCurriculum(CurriculumStatus::Draft);

        self::assertTrue($policy->view($dean, $draft));
    }

    public function test_creating_a_curriculum_requires_a_program_chair_with_a_college(): void
    {
        $policy = new CurriculumPolicy;

        self::assertTrue($policy->create($this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs, 'ccs-chair@grc.test')));
        self::assertFalse($policy->create($this->makeUser(UserRole::ProgramChair, null, 'unassigned-chair@grc.test')));
        self::assertFalse($policy->create($this->makeUser(UserRole::Dean)));
        self::assertFalse($policy->create($this->makeUser(UserRole::Student)));
    }

    public function test_creating_a_curriculum_requires_ownership_of_its_programs_college(): void
    {
        $policy = new CurriculumPolicy;
        $program = Program::create([
            'code' => 'BSED',
            'name' => 'BS Education',
            'college' => CollegeCode::Coe,
            'status' => ProgramStatus::Active,
        ]);

        self::assertTrue($policy->createForProgram($this->makeUser(UserRole::ProgramChair, CollegeCode::Coe, 'coe-chair@grc.test'), $program));
        self::assertFalse($policy->createForProgram($this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs, 'ccs-chair@grc.test'), $program));
        self::assertFalse($policy->createForProgram($this->makeUser(UserRole::ProgramChair, null, 'unassigned-chair@grc.test'), $program));
    }

    public function test_updating_a_curriculum_requires_ownership_of_its_programs_college(): void
    {
        $policy = new CurriculumPolicy;
        $curriculum = $this->makeCurriculum(CurriculumStatus::Draft);

        self::assertTrue($policy->update($this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs, 'ccs-chair@grc.test'), $curriculum));
        self::assertFalse($policy->update($this->makeUser(UserRole::ProgramChair, CollegeCode::Cbae, 'cbae-chair@grc.test'), $curriculum));
        self::assertFalse($policy->update($this->makeUser(UserRole::ProgramChair, null, 'unassigned-chair@grc.test'), $curriculum));
        self::assertFalse($policy->update($this->makeUser(UserRole::RegistrarHead), $curriculum));
    }

    public function test_submit_requires_program_chair_role_and_a_matching_college(): void
    {
        $ccsProgram = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active]);
        $cbaProgram = Program::create(['code' => 'BSA', 'name' => 'BS Accountancy', 'college' => CollegeCode::Cbae, 'status' => ProgramStatus::Active]);
        $ccsCurriculum = Curriculum::create(['program_id' => $ccsProgram->id, 'name' => 'BSCS Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Draft]);
        $cbaCurriculum = Curriculum::create(['program_id' => $cbaProgram->id, 'name' => 'BSA Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Draft]);

        $ccsChair = User::create(['name' => 'CCS Chair', 'email' => 'ccs-chair@grc.test', 'password' => 'irrelevant-password', 'role' => UserRole::ProgramChair, 'college' => CollegeCode::Ccs, 'status' => UserStatus::Active]);
        $noCollegeChair = User::create(['name' => 'No College Chair', 'email' => 'no-college-chair@grc.test', 'password' => 'irrelevant-password', 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active]);
        $dean = $this->makeUser(UserRole::Dean);

        self::assertTrue((new CurriculumPolicy)->submit($ccsChair, $ccsCurriculum));
        self::assertFalse((new CurriculumPolicy)->submit($ccsChair, $cbaCurriculum));
        self::assertFalse((new CurriculumPolicy)->submit($noCollegeChair, $ccsCurriculum));
        self::assertFalse((new CurriculumPolicy)->submit($dean, $ccsCurriculum));
    }

    public function test_only_dean_can_approve_as_dean(): void
    {
        $curriculum = $this->makeCurriculum(CurriculumStatus::PendingDeanReview);
        $dean = $this->makeUser(UserRole::Dean, CollegeCode::Ccs);
        $otherCollegeDean = $this->makeUser(UserRole::Dean, CollegeCode::Cbae, 'cbae-dean@grc.test');
        $chair = $this->makeUser(UserRole::ProgramChair);

        self::assertTrue((new CurriculumPolicy)->approveAsDean($dean, $curriculum));
        self::assertFalse((new CurriculumPolicy)->approveAsDean($otherCollegeDean, $curriculum));
        self::assertFalse((new CurriculumPolicy)->approveAsDean($chair, $curriculum));
    }

    public function test_only_executive_director_can_approve_as_executive(): void
    {
        $executive = $this->makeUser(UserRole::ExecutiveDirector);
        $dean = $this->makeUser(UserRole::Dean);

        self::assertTrue((new CurriculumPolicy)->approveAsExecutive($executive));
        self::assertFalse((new CurriculumPolicy)->approveAsExecutive($dean));
    }
}
