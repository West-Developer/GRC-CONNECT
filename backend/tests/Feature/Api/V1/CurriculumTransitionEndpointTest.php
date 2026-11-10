<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Notification;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every scenario below authenticates as exactly one user per test method
 * (any "prior" state — e.g. a curriculum that's already been submitted —
 * is built directly via Eloquent, not via a live HTTP call as a different
 * user first). This is a deliberate departure from chaining several
 * withToken() calls for different users within one test method: that shape
 * silently breaks in this codebase — the sanctum guard resolves and caches
 * a user once per guard instance, and that cache outlives a single
 * simulated request within a test method, so the second user's request
 * gets authorized as the *first* user instead. See
 * ScheduleProposalsEndpointTest for the same established pattern.
 */
final class CurriculumTransitionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email, ?CollegeCode $college = null): string
    {
        User::create([
            'name' => 'Test '.$role->value,
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

    private function makeCurriculum(CurriculumStatus $status): Curriculum
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active]);

        return Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS Curriculum 2026-2027',
            'effective_school_year' => '2026-2027',
            'status' => $status,
        ]);
    }

    /**
     * A plain (never logged-in) Program Chair row, used only to stand in as
     * the "original submitter" (`decided_by`) when a test's precondition is
     * a curriculum that has already been submitted.
     */
    private function makeSubmitter(): User
    {
        return User::create([
            'name' => 'Original Submitter',
            'email' => 'submitter'.User::query()->count().'@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::ProgramChair,
            'college' => CollegeCode::Ccs,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $curriculum = $this->makeCurriculum(CurriculumStatus::Draft);

        $this->patchJson("/api/v1/curricula/{$curriculum->id}/transition", ['action' => 'submit'])
            ->assertUnauthorized();
    }

    public function test_chair_submits_a_draft_for_dean_review_and_every_dean_is_notified(): void
    {
        $chairToken = $this->tokenFor(UserRole::ProgramChair, 'chair@grc.test', CollegeCode::Ccs);
        $this->tokenFor(UserRole::Dean, 'dean1@grc.test');
        $this->tokenFor(UserRole::Dean, 'dean2@grc.test');
        $curriculum = $this->makeCurriculum(CurriculumStatus::Draft);

        $response = $this->withToken($chairToken)->patchJson("/api/v1/curricula/{$curriculum->id}/transition", ['action' => 'submit']);

        $response->assertOk();
        self::assertSame('pending_dean_review', $response->json('data.status'));
        $curriculum->refresh();
        self::assertSame('pending_dean_review', $curriculum->status->value);
        self::assertNotNull($curriculum->decided_at);

        self::assertSame(2, Notification::query()->where('type', NotificationType::CurriculumSubmittedForDean->value)->count());
        self::assertSame(
            1,
            AuditLog::query()->where('action', 'curriculum.submitted')->where('auditable_id', $curriculum->id)->count(),
        );
    }

    public function test_submit_is_rejected_when_the_curriculum_is_not_a_draft(): void
    {
        $chairToken = $this->tokenFor(UserRole::ProgramChair, 'chair@grc.test', CollegeCode::Ccs);
        $curriculum = $this->makeCurriculum(CurriculumStatus::PendingDeanReview);

        $this->withToken($chairToken)
            ->patchJson("/api/v1/curricula/{$curriculum->id}/transition", ['action' => 'submit'])
            ->assertUnprocessable();
    }

    public function test_dean_approves_a_submission_and_every_executive_director_is_notified(): void
    {
        $submitter = $this->makeSubmitter();
        $deanToken = $this->tokenFor(UserRole::Dean, 'dean@grc.test', CollegeCode::Ccs);
        $this->tokenFor(UserRole::ExecutiveDirector, 'exec@grc.test');
        $curriculum = $this->makeCurriculum(CurriculumStatus::PendingDeanReview);
        $curriculum->update(['decided_by' => $submitter->id, 'decided_at' => now()]);

        $response = $this->withToken($deanToken)->patchJson("/api/v1/curricula/{$curriculum->id}/transition", ['action' => 'dean_approve']);

        $response->assertOk();
        self::assertSame('pending_executive_review', $response->json('data.status'));

        // Both the submitter and every active Executive Director share
        // NotificationType::CurriculumDeanApproved (there's no separate
        // type for "ready for your review" — see
        // CurriculumTransitionNotificationPlan::forAction()'s 'dean_approve'
        // branch), so a submitter + one Executive Director yields 2 rows,
        // not 1.
        self::assertSame(2, Notification::query()->where('type', NotificationType::CurriculumDeanApproved->value)->count());
    }

    public function test_dean_return_requires_a_reason_and_sends_the_curriculum_back_to_draft(): void
    {
        $submitter = $this->makeSubmitter();
        $deanToken = $this->tokenFor(UserRole::Dean, 'dean@grc.test', CollegeCode::Ccs);
        $curriculum = $this->makeCurriculum(CurriculumStatus::PendingDeanReview);
        $curriculum->update(['decided_by' => $submitter->id, 'decided_at' => now()]);

        $this->withToken($deanToken)
            ->patchJson("/api/v1/curricula/{$curriculum->id}/transition", ['action' => 'dean_return'])
            ->assertUnprocessable();

        $response = $this->withToken($deanToken)->patchJson("/api/v1/curricula/{$curriculum->id}/transition", [
            'action' => 'dean_return',
            'reason' => 'Missing PATHFIT 2.',
        ]);

        $response->assertOk();
        self::assertSame('draft', $response->json('data.status'));
        $curriculum->refresh();
        self::assertSame('Missing PATHFIT 2.', $curriculum->last_decision_reason);
    }

    public function test_executive_approves_and_activates_the_curriculum(): void
    {
        $submitter = $this->makeSubmitter();
        $executiveToken = $this->tokenFor(UserRole::ExecutiveDirector, 'exec@grc.test');
        $curriculum = $this->makeCurriculum(CurriculumStatus::PendingExecutiveReview);
        $curriculum->update(['decided_by' => $submitter->id, 'decided_at' => now()]);

        $response = $this->withToken($executiveToken)->patchJson("/api/v1/curricula/{$curriculum->id}/transition", ['action' => 'executive_approve']);

        $response->assertOk();
        self::assertSame('active', $response->json('data.status'));
        self::assertSame(1, Notification::query()->where('type', NotificationType::CurriculumExecutiveApproved->value)->count());
    }

    public function test_a_dean_cannot_perform_an_executive_action(): void
    {
        $deanToken = $this->tokenFor(UserRole::Dean, 'dean@grc.test');
        $curriculum = $this->makeCurriculum(CurriculumStatus::PendingExecutiveReview);

        $this->withToken($deanToken)
            ->patchJson("/api/v1/curricula/{$curriculum->id}/transition", ['action' => 'executive_approve'])
            ->assertForbidden();
    }

    public function test_a_dean_from_a_different_college_cannot_approve_someone_elses_curriculum(): void
    {
        $deanToken = $this->tokenFor(UserRole::Dean, 'cbae-dean@grc.test', CollegeCode::Cbae);
        $curriculum = $this->makeCurriculum(CurriculumStatus::PendingDeanReview);

        $this->withToken($deanToken)
            ->patchJson("/api/v1/curricula/{$curriculum->id}/transition", ['action' => 'dean_approve'])
            ->assertForbidden();
    }

    public function test_a_program_chair_from_a_different_college_cannot_submit_someone_elses_curriculum(): void
    {
        // makeCurriculum() builds its Program with CollegeCode::Ccs — a
        // chair whose own college is Cbae must be rejected, just like
        // Dean review is scoped to the curriculum's program college.
        $chairToken = $this->tokenFor(UserRole::ProgramChair, 'other-chair@grc.test', CollegeCode::Cbae);
        $curriculum = $this->makeCurriculum(CurriculumStatus::Draft);

        $this->withToken($chairToken)
            ->patchJson("/api/v1/curricula/{$curriculum->id}/transition", ['action' => 'submit'])
            ->assertForbidden();
    }
}
