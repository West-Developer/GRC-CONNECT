<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Domain\Scheduling\SectionModality;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Notification;
use App\Models\Program;
use App\Models\ScheduleProposal;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScheduleProposalsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/schedule-proposals')->assertUnauthorized();
        $this->postJson('/api/v1/schedule-proposals', [])->assertUnauthorized();
    }

    public function test_dean_can_view_the_submitted_schedule_for_one_proposal_college(): void
    {
        $term = $this->makeTerm();
        $program = Program::create(['code' => 'BSCS', 'name' => 'Computer Science', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSCS 2026', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'college' => CollegeCode::Ccs->value,
            'year_level' => 1,
            'section_count' => 1,
            'status' => SectionPlanStatus::Submitted,
        ]);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Programming 1', 'units' => 3, 'status' => SubjectStatus::Active]);
        $professor = User::create(['name' => 'Professor Santos', 'email' => 'professor.review@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'college' => CollegeCode::Ccs, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $section = Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $subject->id,
            'section_code' => 'IT101',
            'professor_id' => $professor->id,
            'schedule_days' => 'M',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:30:00',
            'room' => 'LAB 1',
            'modality' => SectionModality::FaceToFace,
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);
        $chair = User::create(['name' => 'CCS Program Chair', 'email' => 'chair.review@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'college' => CollegeCode::Ccs, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $proposal = ScheduleProposal::create([
            'academic_term_id' => $term->id,
            'college' => CollegeCode::Ccs->value,
            'section_plan_id' => $plan->id,
            'submitted_by' => $chair->id,
            'status' => ScheduleProposalStatus::Draft,
        ]);
        $deanToken = $this->tokenFor(UserRole::Dean, 'dean.review@grc.test');

        $response = $this->withToken($deanToken)->getJson("/api/v1/schedule-proposals/{$proposal->id}/sections");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $section->id)
            ->assertJsonPath('data.0.subject_code', 'CS101')
            ->assertJsonPath('data.0.professor_name', 'Professor Santos')
            ->assertJsonPath('data.0.room', 'LAB 1');
    }

    public function test_a_program_chair_can_submit_a_proposal(): void
    {
        $term = $this->makeTerm();
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.submit@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/schedule-proposals', [
            'academic_term_id' => $term->id,
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('schedule_proposals', ['academic_term_id' => $term->id, 'status' => 'draft']);
        self::assertSame(AuditAction::SCHEDULE_PROPOSAL_CREATED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_a_non_program_chair_role_cannot_submit_a_proposal(): void
    {
        $term = $this->makeTerm();
        $token = $this->tokenFor(UserRole::Dean, 'dean.submit@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/schedule-proposals', [
            'academic_term_id' => $term->id,
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_a_term_cannot_have_two_active_proposals(): void
    {
        $term = $this->makeTerm();
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.dup@grc.test');

        $this->withToken($token)->postJson('/api/v1/schedule-proposals', [
            'academic_term_id' => $term->id,
        ])->assertCreated();
        $auditCountBeforeRejection = AuditLog::query()->count();

        $response = $this->withToken($token)->postJson('/api/v1/schedule-proposals', [
            'academic_term_id' => $term->id,
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame($auditCountBeforeRejection, AuditLog::query()->count());
    }

    public function test_a_new_proposal_is_allowed_once_the_prior_one_is_closed(): void
    {
        $term = $this->makeTerm();
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.reopen@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::Closed]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'chair.reopen@grc.test', 'password' => self::PASSWORD,
        ])->json('data.token');

        $response = $this->withToken($token)->postJson('/api/v1/schedule-proposals', [
            'academic_term_id' => $term->id,
        ]);

        $response->assertCreated();
        self::assertSame(AuditAction::SCHEDULE_PROPOSAL_CREATED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_a_learner_scoped_role_does_not_see_a_draft_proposal(): void
    {
        $term = $this->makeTerm();
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.visibility@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::Draft]);
        $token = $this->tokenFor(UserRole::Student, 'student.visibility@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/schedule-proposals');

        $response->assertOk();
        self::assertCount(0, $response->json('data'));
    }

    /**
     * The full lifecycle (draft -> dean_approved -> published -> closed) is
     * exercised across these tests rather than one chained walk — each
     * precreates the proposal directly at whatever status the transition
     * under test requires and authenticates as exactly one actor, matching
     * every other endpoint test in this suite.
     */
    public function test_dean_approve_transitions_a_draft_proposal(): void
    {
        $term = $this->makeTerm();
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.deanapprove@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $proposal = ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::Draft]);
        $deanToken = $this->tokenFor(UserRole::Dean, 'dean.deanapprove@grc.test');

        $response = $this->withToken($deanToken)->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'dean_approve']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'dean_approved')
            ->assertJsonPath('data.decided_by_name', 'Test dean')
            ->assertJsonPath('data.decision_history.0.action', 'dean_approve')
            ->assertJsonPath('data.decision_history.0.action_label', 'Approved by Dean')
            ->assertJsonPath('data.decision_history.0.actor_name', 'Test dean')
            ->assertJsonPath('data.decision_history.0.actor_role', 'dean')
            ->assertJsonPath('data.decision_history.0.notes', null);
        self::assertNotNull($proposal->refresh()->decided_by);
        self::assertSame(
            AuditAction::SCHEDULE_PROPOSAL_DEAN_APPROVED,
            AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action,
        );
    }

    /**
     * `executive_approve` no longer exists — the Executive Director's
     * forward path from a Dean-approved proposal is a direct `publish`
     * (see `test_publish_transitions_the_proposal_and_publishes_the_terms_planned_sections`)
     * or `executive_return`, not a separate approval checkpoint.
     */
    public function test_executive_approve_is_no_longer_a_valid_action(): void
    {
        $term = $this->makeTerm();
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.execapprove@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $proposal = ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::DeanApproved]);
        $executiveToken = $this->tokenFor(UserRole::ExecutiveDirector, 'executive.execapprove@grc.test');

        $response = $this->withToken($executiveToken)->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'executive_approve']);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
        self::assertSame('dean_approved', $proposal->refresh()->status->value);
    }

    /**
     * Direct proof that `publish` is what exposes the term's schedule
     * (FR-SCH-009): the section stays `planned` right up until this exact
     * transition, then flips to `published` in the same request. Also
     * proof of the simplified Executive Director lifecycle: `publish` is
     * legal straight from `dean_approved`, with no separate approval step.
     */
    public function test_publish_transitions_the_proposal_and_publishes_the_terms_planned_sections(): void
    {
        $term = $this->makeTerm();
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Test', 'units' => 3, 'status' => SubjectStatus::Active]);
        $section = Section::create(['academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Planned]);
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.publish@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $proposal = ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::DeanApproved]);
        $executiveToken = $this->tokenFor(UserRole::ExecutiveDirector, 'executive.publish@grc.test');

        self::assertSame('planned', $section->refresh()->status->value);

        $response = $this->withToken($executiveToken)->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'publish']);

        $response->assertOk()->assertJsonPath('data.status', 'published');
        self::assertSame('published', $section->refresh()->status->value);
        self::assertSame(
            [AuditAction::SECTION_PUBLISHED, AuditAction::SCHEDULE_PROPOSAL_PUBLISHED],
            AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->orderBy('id')->pluck('action')->all(),
        );
        self::assertSame([$chair->id], Notification::query()->pluck('user_id')->all());
    }

    public function test_close_transitions_a_published_proposal(): void
    {
        $term = $this->makeTerm();
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.close@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $proposal = ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::Published]);
        $registrarToken = $this->tokenFor(UserRole::RegistrarHead, 'registrar.close@grc.test');

        $response = $this->withToken($registrarToken)->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'close']);

        $response->assertOk()->assertJsonPath('data.status', 'closed');
        self::assertSame(AuditAction::SCHEDULE_PROPOSAL_CLOSED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_a_dean_cannot_approve_a_proposal_that_is_not_in_draft(): void
    {
        $term = $this->makeTerm();
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.wrongstate@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $proposal = ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::DeanApproved]);
        $deanToken = $this->tokenFor(UserRole::Dean, 'dean.wrongstate@grc.test');

        $response = $this->withToken($deanToken)->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'dean_approve']);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_an_executive_director_cannot_perform_the_deans_approval(): void
    {
        $term = $this->makeTerm();
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.wrongrole@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $proposal = ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::Draft]);
        $executiveToken = $this->tokenFor(UserRole::ExecutiveDirector, 'executive.wrongrole@grc.test');

        $response = $this->withToken($executiveToken)->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'dean_approve']);

        $response->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        self::assertSame('draft', $proposal->refresh()->status->value);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_returning_a_proposal_to_draft_requires_a_reason(): void
    {
        $term = $this->makeTerm();
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.reason@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $proposal = ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::DeanApproved]);
        $executiveToken = $this->tokenFor(UserRole::ExecutiveDirector, 'executive.reason@grc.test');

        $withoutReason = $this->withToken($executiveToken)->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'executive_return']);
        $withoutReason->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());

        $withReason = $this->withToken($executiveToken)->patchJson("/api/v1/schedule-proposals/{$proposal->id}", [
            'action' => 'executive_return',
            'decision_reason' => 'Missing a required general education subject.',
        ]);
        $withReason->assertOk()->assertJsonPath('data.status', 'draft');
        $withReason->assertJsonPath('data.decision_reason', 'Missing a required general education subject.');
        $audit = AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole();
        self::assertSame(AuditAction::SCHEDULE_PROPOSAL_EXECUTIVE_RETURNED, $audit->action);
        self::assertSame('Missing a required general education subject.', $audit->reason);
    }

    public function test_a_registrar_head_cannot_publish(): void
    {
        $term = $this->makeTerm();
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.registrarpublish@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $proposal = ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::DeanApproved]);
        $registrarToken = $this->tokenFor(UserRole::RegistrarHead, 'registrar.registrarpublish@grc.test');

        $response = $this->withToken($registrarToken)->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'publish']);

        $response->assertForbidden();
        self::assertSame('dean_approved', $proposal->refresh()->status->value);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_returning_a_proposal_as_acting_dean_emits_dean_in_returned_by_role_and_decision_history(): void
    {
        $term = $this->makeTerm();
        $chair = User::create([
            'name' => 'Chair',
            'email' => 'chair.acting-dean@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::ProgramChair,
            'college' => CollegeCode::Ccs,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
        $proposal = ScheduleProposal::create([
            'academic_term_id' => $term->id,
            'college' => 'ccs',
            'submitted_by' => $chair->id,
            'status' => ScheduleProposalStatus::Draft,
        ]);

        $superAdminToken = $this->tokenFor(UserRole::SuperAdmin, 'superadmin.acting-dean@grc.test');

        // Switch to dean CCS
        $this->withToken($superAdminToken)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => 'dean',
                'college' => 'ccs',
            ])
            ->assertOk();

        auth()->forgetGuards();

        $response = $this->withToken($superAdminToken)
            ->patchJson("/api/v1/schedule-proposals/{$proposal->id}", [
                'action' => 'dean_return',
                'decision_reason' => 'Need revisions.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.returned_by_role', 'dean')
            ->assertJsonPath('data.decision_history.0.actor_role', 'dean');
    }
}

