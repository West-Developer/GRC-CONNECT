<?php

namespace Tests\Feature\Database;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Models\AcademicTerm;
use App\Models\ScheduleProposal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ScheduleProposalMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_proposals_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('schedule_proposals'));
        $this->assertTrue(Schema::hasColumns('schedule_proposals', [
            'id', 'academic_term_id', 'submitted_by', 'status', 'decided_by',
            'decided_at', 'decision_reason', 'created_at', 'updated_at',
        ]));
    }

    public function test_decision_reason_is_nullable(): void
    {
        $proposal = ScheduleProposal::create([
            'academic_term_id' => $this->makeTerm()->id,
            'submitted_by' => $this->makeUser(UserRole::ProgramChair)->id,
            'status' => ScheduleProposalStatus::Draft,
        ]);

        $this->assertNull($proposal->decision_reason);
    }

    public function test_a_term_with_a_proposal_cannot_be_deleted(): void
    {
        $term = $this->makeTerm();

        ScheduleProposal::create([
            'academic_term_id' => $term->id,
            'submitted_by' => $this->makeUser(UserRole::ProgramChair)->id,
            'status' => ScheduleProposalStatus::Draft,
        ]);

        $this->expectException(QueryException::class);

        $term->delete();
    }

    public function test_the_submitter_cannot_be_deleted_while_referenced(): void
    {
        $submitter = $this->makeUser(UserRole::ProgramChair);

        ScheduleProposal::create([
            'academic_term_id' => $this->makeTerm()->id,
            'submitted_by' => $submitter->id,
            'status' => ScheduleProposalStatus::Draft,
        ]);

        $this->expectException(QueryException::class);

        $submitter->delete();
    }

    public function test_deleting_the_decider_nulls_the_reference_rather_than_the_proposal(): void
    {
        $dean = $this->makeUser(UserRole::Dean);

        $proposal = ScheduleProposal::create([
            'academic_term_id' => $this->makeTerm()->id,
            'submitted_by' => $this->makeUser(UserRole::ProgramChair)->id,
            'status' => ScheduleProposalStatus::DeanApproved,
            'decided_by' => $dean->id,
            'decided_at' => now(),
        ]);

        $dean->delete();

        $this->assertNull($proposal->refresh()->decided_by);
        $this->assertDatabaseHas('schedule_proposals', ['id' => $proposal->id]);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeUser(UserRole $role): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $role->value.'.'.uniqid().'@grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }
}
