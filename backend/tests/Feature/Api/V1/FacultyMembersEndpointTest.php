<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FacultyMembersEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_requests_receive_the_standard_unauthenticated_response(): void
    {
        $this->getJson('/api/v1/faculty-members')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[DataProvider('nonChairRoleProvider')]
    public function test_every_non_program_chair_role_is_forbidden(UserRole $role): void
    {
        $user = $this->makeUser('forbidden-'.$role->value, $role);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/faculty-members')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        self::assertSame(0, AuditLog::query()->count());
    }

    public function test_program_chair_receives_active_faculty_only_in_name_then_id_order_with_an_audit_record(): void
    {
        $chair = $this->makeUser('directory-chair', UserRole::ProgramChair, null, UserStatus::Active, CollegeCode::Ccs);
        $firstFaculty = $this->makeUser('directory-first', UserRole::Faculty, 'Ada Faculty', UserStatus::Active, CollegeCode::Ccs);
        $secondFaculty = $this->makeUser('directory-second', UserRole::Faculty, 'Ada Faculty', UserStatus::Active, CollegeCode::Ccs);
        $disabledFaculty = $this->makeUser('directory-disabled', UserRole::Faculty, 'Disabled Faculty', UserStatus::Disabled, CollegeCode::Ccs);
        $nonFaculty = $this->makeUser('directory-non-faculty', UserRole::Dean, 'Dean User', UserStatus::Active, CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($chair))
            ->getJson('/api/v1/faculty-members');

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'faculty_member')
            ->assertJsonPath('data.0.id', $firstFaculty->id)
            ->assertJsonPath('data.0.name', 'Ada Faculty')
            ->assertJsonPath('data.0.status', UserStatus::Active->value)
            ->assertJsonPath('data.0.status_label', 'Active')
            ->assertJsonPath('data.1.id', $secondFaculty->id);

        self::assertSame(
            ['type', 'id', 'name', 'college', 'status', 'status_label', 'employment_type', 'employment_type_label', 'planning_unit_reference', 'masters_degree', 'deactivation_reason', 'is_assignable'],
            array_keys($response->json('data.0')),
        );
        $response->assertDontSee($firstFaculty->email);
        $response->assertDontSee($secondFaculty->email);
        $response->assertDontSee($disabledFaculty->email);
        $response->assertDontSee($nonFaculty->email);
        self::assertNull($response->json('data.2'));

        $this->withToken($this->tokenFor($chair))
            ->getJson('/api/v1/faculty-members?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.id', $disabledFaculty->id)
            ->assertJsonPath('data.2.status_label', 'Inactive')
            ->assertJsonPath('data.2.is_assignable', false);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $chair->id,
            'action' => AuditAction::FACULTY_DIRECTORY_LIST_VIEWED,
            'auditable_type' => AuditableType::FACULTY_DIRECTORY,
            'auditable_id' => null,
            'after_values->result_count' => 2,
        ]);
    }

    public function test_an_audit_write_failure_returns_no_directory_payload(): void
    {
        $chair = $this->makeUser('failing-directory-chair', UserRole::ProgramChair, null, UserStatus::Active, CollegeCode::Ccs);
        $this->makeUser('failing-directory-faculty', UserRole::Faculty, null, UserStatus::Active, CollegeCode::Ccs);

        AuditLog::creating(static function (): never {
            throw new \RuntimeException('Injected audit write failure.');
        });

        try {
            $response = $this->withToken($this->tokenFor($chair))
                ->getJson('/api/v1/faculty-members');

            $response
                ->assertStatus(500)
                ->assertJsonPath('data', null);

            self::assertSame(0, AuditLog::query()->count());
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }
    }

    public function test_a_program_chair_can_audit_an_employment_and_active_status_change_only_for_their_college(): void
    {
        $chair = $this->makeUser('workforce-chair', UserRole::ProgramChair, 'Chair User', UserStatus::Active, CollegeCode::Ccs);
        $faculty = $this->makeUser('workforce-faculty', UserRole::Faculty, 'Ada Faculty', UserStatus::Active, CollegeCode::Ccs);
        $otherCollegeFaculty = $this->makeUser('workforce-other', UserRole::Faculty, 'Bea Faculty', UserStatus::Active, CollegeCode::Coe);

        $response = $this->withToken($this->tokenFor($chair))
            ->patchJson("/api/v1/faculty-members/{$faculty->id}/workforce-profile", [
                'status' => UserStatus::Disabled->value,
                'employment_type' => 'part_time',
                'reason' => 'No current teaching assignment.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', UserStatus::Disabled->value)
            ->assertJsonPath('data.employment_type', 'part_time')
            ->assertJsonPath('data.is_assignable', false);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $chair->id,
            'auditable_id' => $faculty->id,
            'reason' => 'No current teaching assignment.',
        ]);

        $this->withToken($this->tokenFor($chair))
            ->patchJson("/api/v1/faculty-members/{$otherCollegeFaculty->id}/workforce-profile", [
                'status' => UserStatus::Active->value,
                'employment_type' => 'full_time',
            ])
            ->assertForbidden();
    }

    public function test_making_an_active_faculty_member_inactive_requires_a_reason(): void
    {
        $chair = $this->makeUser('reason-chair', UserRole::ProgramChair, 'Chair User', UserStatus::Active, CollegeCode::Ccs);
        $faculty = $this->makeUser('reason-faculty', UserRole::Faculty, 'Ada Faculty', UserStatus::Active, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))
            ->patchJson("/api/v1/faculty-members/{$faculty->id}/workforce-profile", [
                'status' => UserStatus::Disabled->value,
                'employment_type' => 'part_time',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.errors.reason.0',
                'Provide a reason when making an active faculty account inactive.',
            );

        $this->assertDatabaseHas('users', [
            'id' => $faculty->id,
            'status' => UserStatus::Active->value,
        ]);
    }

    public function test_registrar_head_sees_faculty_across_every_college_and_can_filter_by_one(): void
    {
        $registrarHead = $this->makeUser('directory-registrar', UserRole::RegistrarHead, 'Registrar Head', UserStatus::Active, null);
        $ccsFaculty = $this->makeUser('directory-ccs', UserRole::Faculty, 'CCS Faculty', UserStatus::Active, CollegeCode::Ccs);
        $coeFaculty = $this->makeUser('directory-coe', UserRole::Faculty, 'COE Faculty', UserStatus::Active, CollegeCode::Coe);

        $this->withToken($this->tokenFor($registrarHead))
            ->getJson('/api/v1/faculty-members')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withToken($this->tokenFor($registrarHead))
            ->getJson('/api/v1/faculty-members?college=coe')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $coeFaculty->id);

        self::assertNotSame($ccsFaculty->id, $coeFaculty->id);
    }

    public function test_registrar_head_cannot_edit_a_workforce_profile(): void
    {
        $registrarHead = $this->makeUser('workforce-registrar', UserRole::RegistrarHead, 'Registrar Head', UserStatus::Active, null);
        $faculty = $this->makeUser('workforce-registrar-target', UserRole::Faculty, 'Target Faculty', UserStatus::Active, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($registrarHead))
            ->patchJson("/api/v1/faculty-members/{$faculty->id}/workforce-profile", [
                'status' => UserStatus::Disabled->value,
                'employment_type' => 'part_time',
                'reason' => 'Should not be allowed.',
            ])
            ->assertForbidden();
    }

    public function test_faculty_member_can_view_own_record_only(): void
    {
        $facultyMe = $this->makeUser('faculty-me', UserRole::Faculty, 'Diana Santos', UserStatus::Active, CollegeCode::Ccs);
        $facultyOther = $this->makeUser('faculty-other', UserRole::Faculty, 'Other Professor', UserStatus::Active, CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($facultyMe))
            ->getJson('/api/v1/faculty-members');

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $facultyMe->id)
            ->assertJsonPath('data.0.name', 'Diana Santos');
    }

    /**
     * @return iterable<string, array{UserRole}>
     */
    public static function nonChairRoleProvider(): iterable
    {
        foreach (UserRole::cases() as $role) {
            if ($role !== UserRole::ProgramChair && $role !== UserRole::RegistrarHead && $role !== UserRole::Faculty) {
                yield $role->value => [$role];
            }
        }
    }

    private function makeUser(
        string $handle,
        UserRole $role = UserRole::Student,
        ?string $name = null,
        UserStatus $status = UserStatus::Active,
        ?CollegeCode $college = null,
    ): User {
        return User::create([
            'name' => $name ?? 'Faculty directory '.$handle,
            'email' => $handle.'@private.grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'college' => $college,
            'status' => $status,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('faculty-member-test')->plainTextToken;
    }
}
