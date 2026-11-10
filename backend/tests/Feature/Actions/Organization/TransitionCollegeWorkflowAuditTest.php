<?php

namespace Tests\Feature\Actions\Organization;

use App\Actions\Organization\TransitionCollegeWorkflow;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TransitionCollegeWorkflowAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_curriculum_preparation_records_the_actor_and_stage(): void
    {
        $actor = User::create([
            'name' => 'CCS Chair',
            'email' => 'workflow-start@grc.test',
            'password' => 'password',
            'role' => UserRole::ProgramChair,
            'college' => CollegeCode::Ccs,
            'status' => UserStatus::Active,
        ]);
        $term = AcademicTerm::create(['school_year' => '2028-2029', 'semester' => '1st', 'status' => 'draft']);
        $workflow = AcademicTermCollegeWorkflow::create([
            'academic_term_id' => $term->id,
            'college' => CollegeCode::Ccs,
            'stage' => AcademicTermCollegeWorkflowStage::Draft,
        ]);

        $updated = app(TransitionCollegeWorkflow::class)->execute(
            $workflow,
            'start_curriculum_preparation',
            $actor,
            new AuditRequestContext('workflow-start', '127.0.0.1'),
        );

        self::assertSame(AcademicTermCollegeWorkflowStage::CurriculumPreparation, $updated->stage);
        self::assertDatabaseHas('audit_logs', [
            'auditable_type' => 'academic_term_workflow',
            'auditable_id' => $workflow->id,
            'actor_user_id' => $actor->id,
            'action' => 'academic_term_workflow.curriculum_started',
        ]);
    }
}
