<?php

namespace Tests\Unit\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\User;
use App\Policies\AcademicTermCollegeWorkflowPolicy;
use Tests\TestCase;

final class AcademicTermCollegeWorkflowPolicyTest extends TestCase
{
    public function test_program_chair_can_view_and_update_only_their_college(): void
    {
        $chair = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);
        $workflow = new AcademicTermCollegeWorkflow;
        $workflow->forceFill(['college' => CollegeCode::Ccs, 'stage' => AcademicTermCollegeWorkflowStage::Draft]);

        $policy = new AcademicTermCollegeWorkflowPolicy;

        self::assertTrue($policy->view($chair, $workflow));
        self::assertTrue($policy->update($chair, $workflow));
    }

    public function test_program_chair_cannot_view_another_college_workflow(): void
    {
        $chair = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);
        $workflow = new AcademicTermCollegeWorkflow;
        $workflow->forceFill(['college' => CollegeCode::Coe, 'stage' => AcademicTermCollegeWorkflowStage::Draft]);

        $policy = new AcademicTermCollegeWorkflowPolicy;

        self::assertFalse($policy->view($chair, $workflow));
        self::assertFalse($policy->update($chair, $workflow));
    }

    public function test_registrar_head_can_view_all_workflows_but_cannot_transition_them(): void
    {
        $registrar = $this->user(UserRole::RegistrarHead, null);
        $workflow = new AcademicTermCollegeWorkflow;
        $workflow->forceFill(['college' => CollegeCode::Cbae, 'stage' => AcademicTermCollegeWorkflowStage::Draft]);

        $policy = new AcademicTermCollegeWorkflowPolicy;

        self::assertTrue($policy->view($registrar, $workflow));
        self::assertFalse($policy->update($registrar, $workflow));
    }

    private function user(UserRole $role, ?CollegeCode $college): User
    {
        return new User([
            'name' => 'Policy User',
            'email' => 'policy@example.test',
            'password' => 'password',
            'role' => $role,
            'college' => $college,
            'status' => UserStatus::Active,
        ]);
    }
}
