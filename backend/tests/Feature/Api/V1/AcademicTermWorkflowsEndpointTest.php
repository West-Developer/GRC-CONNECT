<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicTermWorkflowsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_registrar_head_sees_all_four_college_workflows(): void
    {
        $term = $this->makeTerm();
        $this->makeWorkflows($term);
        $token = $this->tokenFor(UserRole::RegistrarHead, null, 'workflow-registrar@grc.test');

        $response = $this->withToken($token)->getJson("/api/v1/academic-term-workflows?academic_term_id={$term->id}");

        $response->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_program_chair_sees_only_their_college_workflow(): void
    {
        $term = $this->makeTerm();
        $this->makeWorkflows($term);
        $token = $this->tokenFor(UserRole::ProgramChair, CollegeCode::Ccs, 'workflow-chair@grc.test');

        $response = $this->withToken($token)->getJson("/api/v1/academic-term-workflows?academic_term_id={$term->id}");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.college', CollegeCode::Ccs->value);
    }

    public function test_program_chair_can_start_their_curriculum_preparation(): void
    {
        $term = $this->makeTerm();
        $workflow = AcademicTermCollegeWorkflow::create([
            'academic_term_id' => $term->id,
            'college' => CollegeCode::Ccs,
            'stage' => AcademicTermCollegeWorkflowStage::Draft,
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, CollegeCode::Ccs, 'workflow-transition@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/academic-term-workflows/{$workflow->id}", [
            'action' => 'start_curriculum_preparation',
        ]);

        $response->assertOk()->assertJsonPath('data.stage', AcademicTermCollegeWorkflowStage::CurriculumPreparation->value);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2028-2029',
            'semester' => '1st',
            'status' => 'draft',
        ]);
    }

    private function makeWorkflows(AcademicTerm $term): void
    {
        foreach (CollegeCode::cases() as $college) {
            AcademicTermCollegeWorkflow::create([
                'academic_term_id' => $term->id,
                'college' => $college,
                'stage' => AcademicTermCollegeWorkflowStage::Draft,
            ]);
        }
    }

    private function tokenFor(UserRole $role, ?CollegeCode $college, string $email): string
    {
        User::create([
            'name' => 'Workflow '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'college' => $college,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }
}
