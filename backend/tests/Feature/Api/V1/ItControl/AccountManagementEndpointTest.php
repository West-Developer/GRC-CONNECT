<?php

namespace Tests\Feature\Api\V1\ItControl;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\FacultyAvailability;
use App\Models\Notification;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\AccountSetupCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * IT Control can edit, archive, restore and delete Student and Faculty accounts (ADR 0054). Archiving is the
 * way to retire an account that has history; deleting is only for an account that was never used.
 */
final class AccountManagementEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_admin_edits_a_students_name_and_email_and_the_middle_name_is_left_alone(): void
    {
        [$student] = $this->student('edit', ['middle_name' => 'Ramga', 'middle_initial' => 'R']);
        $itAdmin = $this->makeUser('it-admin', UserRole::ItAdmin);

        $this->withToken($this->tokenFor($itAdmin))->patchJson('/api/v1/it-control/accounts/'.$student->id, [
            'first_name' => 'westley',
            'last_name' => 'RAGMA',
            'suffix' => 'jr.',
            'email' => 'westley.new@grc.test',
        ])->assertOk()
            ->assertJsonPath('data.type', 'it-control-account')
            ->assertJsonPath('data.id', $student->id)
            ->assertJsonPath('data.name', 'Westley R. Ragma Jr.')
            ->assertJsonPath('data.email', 'westley.new@grc.test')
            ->assertJsonMissingPath('data.middle_name');

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => 'Westley R. Ragma Jr.',
            'first_name' => 'Westley',
            'last_name' => 'Ragma',
            'suffix' => 'Jr.',
            'email' => 'westley.new@grc.test',
            'middle_name' => 'Ramga',
            'middle_initial' => 'R',
        ]);

        $audit = AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_UPDATED)->sole();
        $payload = json_encode([$audit->before_values, $audit->after_values], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('email', $payload);
        self::assertStringNotContainsString('westley.new@grc.test', $payload);
        self::assertStringNotContainsString('Ragma', $payload);
    }

    public function test_it_admin_edits_a_faculty_account(): void
    {
        $faculty = $this->makeUser('prof', UserRole::Faculty, 'Old Name', 'prof@grc.test');
        $faculty->forceFill(['first_name' => 'Old', 'last_name' => 'Name', 'middle_initial' => 'M'])->save();

        $this->withToken($this->tokenFor($this->makeUser('it-admin', UserRole::ItAdmin)))
            ->patchJson('/api/v1/it-control/accounts/'.$faculty->id, ['first_name' => 'Maria', 'last_name' => 'Santos'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Maria M. Santos')
            ->assertJsonPath('data.email', 'prof@grc.test');
    }

    public function test_an_edit_needs_a_change_and_a_free_email_address(): void
    {
        [$student] = $this->student('first');
        $this->student('second', ['email' => 'taken@grc.test']);
        $token = $this->tokenFor($this->makeUser('it-admin', UserRole::ItAdmin));

        $this->withToken($token)->patchJson('/api/v1/it-control/accounts/'.$student->id, [])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->withToken($token)->patchJson('/api/v1/it-control/accounts/'.$student->id, ['email' => 'TAKEN@grc.test'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->withToken($token)->patchJson('/api/v1/it-control/accounts/'.$student->id, ['email' => 'not-an-email'])
            ->assertUnprocessable();
        $this->withToken($token)->patchJson('/api/v1/it-control/accounts/'.$student->id, ['first_name' => ''])
            ->assertUnprocessable();

        // Keeping the address it already has is fine.
        $this->withToken($token)->patchJson('/api/v1/it-control/accounts/'.$student->id, ['email' => $student->email, 'last_name' => 'Same'])
            ->assertOk();
    }

    public function test_archiving_blocks_the_account_hides_it_from_the_lists_and_restoring_brings_it_back(): void
    {
        [$student, $profile] = $this->student('archive');
        $faculty = $this->makeUser('prof-archive', UserRole::Faculty);
        $itAdmin = $this->makeUser('it-admin', UserRole::ItAdmin);
        $studentToken = $this->tokenFor($student);
        $token = $this->tokenFor($itAdmin);

        $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$student->id.'/archive')
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.is_archived', true);
        $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$faculty->id.'/archive')->assertOk();

        $student->refresh();
        self::assertNotNull($student->archived_at);
        self::assertSame(UserStatus::Disabled, $student->status);
        self::assertSame(0, $student->tokens()->count(), 'Its sessions are revoked.');
        $this->app['auth']->forgetGuards();
        $this->withToken($studentToken)->getJson('/api/v1/student-profile')->assertUnauthorized();
        // The student's record itself stays: only the account is archived.
        $this->assertDatabaseHas('student_profiles', ['id' => $profile->id]);

        // The default lists leave archived accounts out; the Archived filter shows only them.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/it-control/students')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/it-control/students?status=disabled')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/it-control/students?status=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $student->id)
            ->assertJsonPath('data.0.is_archived', true);
        $this->withToken($token)->getJson('/api/v1/it-control/faculty')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/it-control/faculty?status=archived')->assertOk()->assertJsonCount(1, 'data');

        // Twice is refused; restoring puts an account that had set up its login back to active.
        $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$student->id.'/archive')->assertConflict();
        $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$student->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_archived', false);
        $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$student->id.'/restore')->assertConflict();
        $this->withToken($token)->getJson('/api/v1/it-control/students')->assertOk()->assertJsonCount(1, 'data');

        self::assertSame(1, AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_ARCHIVED)->where('auditable_id', $student->id)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_RESTORED)->where('auditable_id', $student->id)->count());
    }

    public function test_restoring_never_activates_an_account_that_was_not_set_up_or_was_retired_on_purpose(): void
    {
        [$neverSetUp] = $this->student('never', ['setup' => false, 'status' => UserStatus::Disabled]);
        $merged = $this->makeUser('merged', UserRole::Faculty);
        $merged->forceFill(['status' => UserStatus::Disabled, 'deactivation_reason' => 'Merged into faculty account #1'])->save();
        $token = $this->tokenFor($this->makeUser('it-admin', UserRole::ItAdmin));

        foreach ([$neverSetUp, $merged] as $account) {
            $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$account->id.'/archive')->assertOk();
            $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$account->id.'/restore')
                ->assertOk()
                ->assertJsonPath('data.status', 'disabled')
                ->assertJsonPath('data.is_archived', false);
        }
    }

    public function test_an_archived_account_that_never_set_up_cannot_finish_setting_up(): void
    {
        [$student] = $this->student('late', ['setup' => false, 'status' => UserStatus::Disabled]);
        $token = $this->tokenFor($this->makeUser('it-admin', UserRole::ItAdmin));
        $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$student->id.'/archive')->assertOk();

        $code = app(AccountSetupCodes::class)->issue($student->refresh());
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/account-setup', [
            'email' => $student->email,
            'code' => $code,
            'password' => 'A-very-long-passphrase-2026!',
            'password_confirmation' => 'A-very-long-passphrase-2026!',
        ])->assertUnprocessable();
        self::assertNull($student->refresh()->account_setup_completed_at);
        self::assertSame(UserStatus::Disabled, $student->status);
    }

    public function test_an_unused_student_account_can_be_deleted_with_its_profile(): void
    {
        [$student, $profile] = $this->student('unused', ['setup' => false, 'status' => UserStatus::Disabled]);
        Notification::create([
            'user_id' => $student->id,
            'type' => NotificationType::StudentProfileChangeApproved,
            'message' => 'A notice.',
        ]);
        $itAdmin = $this->makeUser('it-admin', UserRole::ItAdmin);

        $this->withToken($this->tokenFor($itAdmin))->deleteJson('/api/v1/it-control/accounts/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.id', $student->id);

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('student_profiles', ['id' => $profile->id]);
        $audit = AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_DELETED)->sole();
        self::assertSame($itAdmin->id, $audit->actor_user_id);
        $payload = json_encode([$audit->before_values, $audit->after_values], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($student->email, $payload);
    }

    public function test_an_unused_faculty_account_can_be_deleted_with_its_setup_data(): void
    {
        $faculty = $this->makeUser('prof-unused', UserRole::Faculty);
        FacultyAvailability::create([
            'professor_id' => $faculty->id, 'day_of_week' => 1, 'starts_at_time' => '08:00:00', 'ends_at_time' => '12:00:00',
        ]);

        $this->withToken($this->tokenFor($this->makeUser('it-admin', UserRole::ItAdmin)))
            ->deleteJson('/api/v1/it-control/accounts/'.$faculty->id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $faculty->id]);
        $this->assertDatabaseMissing('faculty_availabilities', ['professor_id' => $faculty->id]);
    }

    public function test_an_account_with_history_cannot_be_deleted_and_is_left_untouched(): void
    {
        [$student, $profile] = $this->student('history');
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        Enrollment::create([
            'student_id' => $profile->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment, 'total_units' => 18,
        ]);
        $acted = $this->makeUser('acted', UserRole::Faculty);
        app(AuditRecorder::class)->record(
            $acted, AuditAction::USER_ACCOUNT_LIST_VIEWED, AuditableType::USER_ACCOUNT, null, null, null, null,
            new AuditRequestContext('test', null),
        );
        $token = $this->tokenFor($this->makeUser('it-admin', UserRole::ItAdmin));

        foreach ([$student, $acted] as $account) {
            $this->withToken($token)->deleteJson('/api/v1/it-control/accounts/'.$account->id)
                ->assertConflict()
                ->assertJsonPath('error.message', 'This account has records, so it cannot be deleted. Archive it instead.');
            $this->assertDatabaseHas('users', ['id' => $account->id]);
        }
        $this->assertDatabaseHas('student_profiles', ['id' => $profile->id]);
        $this->assertDatabaseHas('enrollments', ['student_id' => $profile->id]);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_DELETED)->count());
    }

    public function test_the_lists_carry_what_the_edit_form_needs(): void
    {
        [$student] = $this->student('rows', ['first_name' => 'Rowan', 'last_name' => 'Rows', 'suffix' => 'III']);
        $faculty = $this->makeUser('prof-rows', UserRole::Faculty, 'Pat Prof', 'pat@grc.test');
        $faculty->forceFill(['first_name' => 'Pat', 'last_name' => 'Prof'])->save();
        $token = $this->tokenFor($this->makeUser('it-admin', UserRole::ItAdmin));

        $this->withToken($token)->getJson('/api/v1/it-control/students')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $student->id)
            ->assertJsonPath('data.0.first_name', 'Rowan')
            ->assertJsonPath('data.0.last_name', 'Rows')
            ->assertJsonPath('data.0.suffix', 'III')
            ->assertJsonPath('data.0.is_archived', false)
            ->assertJsonMissingPath('data.0.middle_name');
        $this->withToken($token)->getJson('/api/v1/it-control/faculty')
            ->assertOk()
            ->assertJsonPath('data.0.first_name', 'Pat')
            ->assertJsonPath('data.0.last_name', 'Prof')
            ->assertJsonPath('data.0.is_archived', false);
    }

    public function test_only_students_and_faculty_can_be_managed_here(): void
    {
        $token = $this->tokenFor($this->makeUser('it-admin', UserRole::ItAdmin));

        foreach ([UserRole::AdmissionStaff, UserRole::RegistrarHead, UserRole::ItAdmin, UserRole::ProgramChair, UserRole::SuperAdmin] as $role) {
            $other = $this->makeUser('other-'.$role->value, $role);
            $this->withToken($token)->patchJson('/api/v1/it-control/accounts/'.$other->id, ['first_name' => 'X'])->assertForbidden();
            $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$other->id.'/archive')->assertForbidden();
            $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$other->id.'/restore')->assertForbidden();
            $this->withToken($token)->deleteJson('/api/v1/it-control/accounts/'.$other->id)->assertForbidden();
            $this->assertDatabaseHas('users', ['id' => $other->id]);
        }
    }

    #[DataProvider('otherRoles')]
    public function test_every_other_role_is_forbidden(UserRole $role): void
    {
        [$student] = $this->student('target');
        $actor = $this->makeUser('actor-'.$role->value, $role);
        $token = $this->tokenFor($actor);

        $this->withToken($token)->patchJson('/api/v1/it-control/accounts/'.$student->id, ['first_name' => 'X'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$student->id.'/archive')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/it-control/accounts/'.$student->id.'/restore')->assertForbidden();
        $this->withToken($token)->deleteJson('/api/v1/it-control/accounts/'.$student->id)->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $student->id, 'first_name' => $student->first_name]);
    }

    public function test_anonymous_requests_are_unauthenticated(): void
    {
        [$student] = $this->student('anon');

        $this->patchJson('/api/v1/it-control/accounts/'.$student->id, ['first_name' => 'X'])->assertUnauthorized();
        $this->postJson('/api/v1/it-control/accounts/'.$student->id.'/archive')->assertUnauthorized();
        $this->deleteJson('/api/v1/it-control/accounts/'.$student->id)->assertUnauthorized();
    }

    /** @return iterable<string, array{UserRole}> */
    public static function otherRoles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            if ($role !== UserRole::ItAdmin) {
                yield $role->value => [$role];
            }
        }
    }

    /**
     * @param  array{email?: string, setup?: bool, status?: UserStatus, first_name?: string, last_name?: string, suffix?: string, middle_name?: string, middle_initial?: string}  $overrides
     * @return array{User, StudentProfile}
     */
    private function student(string $handle, array $overrides = []): array
    {
        $program = Program::query()->first() ?? Program::create([
            'code' => 'BSCS', 'name' => 'BS Computer Science', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::query()->first() ?? Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $user = $this->makeUser($handle, UserRole::Student, 'Student '.$handle, $overrides['email'] ?? $handle.'@grc.test');
        $user->forceFill([
            'first_name' => $overrides['first_name'] ?? 'Student',
            'last_name' => $overrides['last_name'] ?? ucfirst($handle),
            'suffix' => $overrides['suffix'] ?? null,
            'middle_name' => $overrides['middle_name'] ?? null,
            'middle_initial' => $overrides['middle_initial'] ?? null,
            'status' => $overrides['status'] ?? UserStatus::Active,
            'account_setup_completed_at' => ($overrides['setup'] ?? true) ? now() : null,
        ])->save();

        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2026-08-'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'enrollment_category' => 'regular',
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);

        return [$user->refresh(), $profile];
    }

    private function makeUser(string $handle, UserRole $role, ?string $name = null, ?string $email = null): User
    {
        return User::create([
            'name' => $name ?? 'User '.$handle,
            'email' => $email ?? $handle.'@grc.test',
            'password' => 'password',
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
            'account_setup_completed_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('it-control-account-test')->plainTextToken;
    }
}
