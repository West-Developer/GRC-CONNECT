<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Models\AcademicTerm;
use App\Models\ScheduleProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers `open_enrollment`, the transition that moves a term into
 * `semester_ongoing` — the action that did not exist at all before this
 * slice (only a seeder ever wrote that status). See
 * `App\Actions\Organization\TransitionAcademicTerm` and
 * `App\Domain\Enrollment\EnrollmentWindowResolver`, which this action's
 * effect is a precondition for.
 */
final class AcademicTermOpenEnrollmentEndpointTest extends TestCase
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

    private function publishedProposalFor(AcademicTerm $term): void
    {
        $submitter = User::create([
            'name' => 'Submitting Chair',
            'email' => 'chair.open-enrollment@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::ProgramChair,
            'college' => 'ccs',
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        ScheduleProposal::create([
            'academic_term_id' => $term->id,
            'college' => 'ccs',
            'submitted_by' => $submitter->id,
            'status' => ScheduleProposalStatus::Published,
        ]);
    }

    public function test_registrar_head_can_open_enrollment_from_draft_with_a_published_schedule(): void
    {
        $term = AcademicTerm::create(['school_year' => '2028-2029', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);
        $this->publishedProposalFor($term);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.open-enrollment@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/academic-terms/{$term->id}", ['action' => 'open_enrollment']);

        $response->assertOk()->assertJsonPath('data.status', 'semester_ongoing');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'academic_term.enrollment_opened',
            'auditable_type' => 'academic_term',
            'auditable_id' => $term->id,
        ]);
    }

    public function test_registrar_head_can_open_enrollment_from_for_dean_approval(): void
    {
        $term = AcademicTerm::create(['school_year' => '2028-2029', 'semester' => '1st', 'status' => AcademicTermStatus::ForDeanApproval]);
        $this->publishedProposalFor($term);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.open-enrollment-fda@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/academic-terms/{$term->id}", ['action' => 'open_enrollment']);

        $response->assertOk()->assertJsonPath('data.status', 'semester_ongoing');
    }

    public function test_opening_enrollment_without_a_published_schedule_is_rejected(): void
    {
        $term = AcademicTerm::create(['school_year' => '2028-2029', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.open-enrollment-unpublished@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/academic-terms/{$term->id}", ['action' => 'open_enrollment']);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame(AcademicTermStatus::Draft, $term->fresh()->status);
    }

    /**
     * @dataProvider illegalSourceStatuses
     */
    public function test_opening_enrollment_from_an_illegal_status_is_rejected(AcademicTermStatus $status): void
    {
        $term = AcademicTerm::create(['school_year' => '2028-2029', 'semester' => '1st', 'status' => $status]);
        $this->publishedProposalFor($term);
        $token = $this->tokenFor(UserRole::RegistrarHead, "registrar-head.open-enrollment-{$status->value}@grc.test");

        $response = $this->withToken($token)->patchJson("/api/v1/academic-terms/{$term->id}", ['action' => 'open_enrollment']);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    /**
     * `SemesterOngoing` is deliberately absent: per ADR 0018 repeating a
     * lifecycle action returns the existing final state rather than
     * erroring, which `test_opening_enrollment_twice_is_idempotent` covers.
     * Listing it here would assert the opposite of that test.
     *
     * @return array<string, array{AcademicTermStatus}>
     */
    public static function illegalSourceStatuses(): array
    {
        return [
            'semester closed' => [AcademicTermStatus::SemesterClosed],
            'archived' => [AcademicTermStatus::Archived],
        ];
    }

    public function test_opening_enrollment_twice_is_idempotent(): void
    {
        $term = AcademicTerm::create(['school_year' => '2028-2029', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);
        $this->publishedProposalFor($term);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.open-enrollment-idempotent@grc.test');

        $this->withToken($token)->patchJson("/api/v1/academic-terms/{$term->id}", ['action' => 'open_enrollment'])->assertOk();
        $second = $this->withToken($token)->patchJson("/api/v1/academic-terms/{$term->id}", ['action' => 'open_enrollment']);

        $second->assertOk()->assertJsonPath('data.status', 'semester_ongoing');
    }

    /**
     * @dataProvider nonRegistrarHeadRoleProvider
     */
    public function test_a_non_registrar_head_role_cannot_open_enrollment(UserRole $role): void
    {
        $term = AcademicTerm::create(['school_year' => '2028-2029', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);
        $this->publishedProposalFor($term);
        $token = $this->tokenFor($role, $role->value.'.open-enrollment-forbidden@grc.test');

        $this->withToken($token)->patchJson("/api/v1/academic-terms/{$term->id}", ['action' => 'open_enrollment'])->assertForbidden();
    }

    /** @return array<string, array{UserRole}> */
    public static function nonRegistrarHeadRoleProvider(): array
    {
        $roles = [];

        foreach (UserRole::cases() as $role) {
            if ($role === UserRole::RegistrarHead) {
                continue;
            }

            $roles[$role->value] = [$role];
        }

        return $roles;
    }
}
