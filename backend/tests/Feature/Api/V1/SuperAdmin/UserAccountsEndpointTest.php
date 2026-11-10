<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\FinancialStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Mail\PasswordResetMail;
use App\Mail\StaffAccountSetupMail;
use App\Mail\StudentAccountSetupMail;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\FacultyAvailability;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class UserAccountsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeUser(UserRole $role, string $email, ?CollegeCode $college = null, ?UserStatus $status = null): User
    {
        return User::create([
            'name' => 'User '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'college' => $college,
            'status' => $status ?? UserStatus::Active,
            'last_otp_verified_at' => now(),
            'account_setup_completed_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_only_super_admin_can_access_user_accounts_list(): void
    {
        $registrar = $this->makeUser(UserRole::RegistrarHead, 'reg.head@grc.test');
        $token = $this->tokenFor($registrar);

        $this->withToken($token)
            ->getJson('/api/v1/super-admin/users')
            ->assertForbidden();
    }

    public function test_console_mode_only_returns_409_when_acting(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.acting@grc.test');
        $token = $this->tokenFor($admin);

        // Switch to dean
        $this->withToken($token)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => 'dean',
                'college' => 'ccs',
            ])->assertOk();

        // Calling users list while acting returns 409
        $this->withToken($token)
            ->withHeader('X-Acting-Context', 'dean:ccs')
            ->getJson('/api/v1/super-admin/users')
            ->assertStatus(409);
    }

    public function test_super_admin_can_list_managed_users_in_console_mode(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.console@grc.test');
        $token = $this->tokenFor($admin);

        $chair = $this->makeUser(UserRole::ProgramChair, 'chair.ccs@grc.test', CollegeCode::Ccs);

        $response = $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->getJson('/api/v1/super-admin/users');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'id',
                        'name',
                        'email',
                        'role',
                        'role_label',
                        'college',
                        'status',
                        'status_label',
                        'pending_setup',
                        'manageable',
                    ],
                ],
                'meta',
                'links',
            ]);
    }

    public function test_listing_users_is_audited(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.audited@grc.test');
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->getJson('/api/v1/super-admin/users')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_ACCOUNT_LIST_VIEWED,
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_filtering_by_role_status_college_and_search(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.filter@grc.test');
        $token = $this->tokenFor($admin);

        $ccsDean = $this->makeUser(UserRole::Dean, 'dean.ccs@grc.test', CollegeCode::Ccs);
        $coeDean = $this->makeUser(UserRole::Dean, 'dean.coe@grc.test', CollegeCode::Coe);
        $inactiveStaff = $this->makeUser(UserRole::AccountingStaff, 'acc.staff@grc.test', null, UserStatus::Disabled);

        // Filter by role
        $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->getJson('/api/v1/super-admin/users?role=dean')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Filter by college
        $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->getJson('/api/v1/super-admin/users?role=dean&college=ccs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'dean.ccs@grc.test');

        // Filter by status
        $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->getJson('/api/v1/super-admin/users?status=disabled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'acc.staff@grc.test');

        // Search by name/email
        $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->getJson('/api/v1/super-admin/users?q=dean.coe')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'dean.coe@grc.test');
    }

    public function test_super_admin_and_queue_kiosk_are_flagged_not_manageable(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.flags@grc.test');
        $token = $this->tokenFor($admin);

        $kiosk = $this->makeUser(UserRole::QueueKiosk, 'kiosk.device@grc.test');
        $staff = $this->makeUser(UserRole::AdmissionStaff, 'admission.staff@grc.test');

        $response = $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->getJson('/api/v1/super-admin/users');

        $response->assertOk();

        $users = collect($response->json('data'));

        $adminRow = $users->firstWhere('id', $admin->id);
        $kioskRow = $users->firstWhere('id', $kiosk->id);
        $staffRow = $users->firstWhere('id', $staff->id);

        $this->assertFalse($adminRow['manageable']);
        $this->assertFalse($kioskRow['manageable']);
        $this->assertTrue($staffRow['manageable']);
    }

    public function test_super_admin_can_invite_admission_staff_and_redeem(): void
    {
        Mail::fake();
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.inv.adm@grc.test');
        $token = $this->tokenFor($admin);

        $response = $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson('/api/v1/super-admin/users/invite', [
                'email' => 'new.admission@grc.test',
                'role' => 'admission_staff',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'admission_staff')
            ->assertJsonPath('data.email', 'new.admission@grc.test')
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.pending_setup', true);

        $staff = User::where('email', 'new.admission@grc.test')->firstOrFail();
        $this->assertNull($staff->account_setup_completed_at);

        // Redeem the setup invitation
        $setupCode = null;
        Mail::assertSent(StaffAccountSetupMail::class, function (StaffAccountSetupMail $mail) use (&$setupCode): bool {
            $setupCode = $mail->setupCode;

            return true;
        });
        $this->assertNotNull($setupCode);

        $redeemResponse = $this->postJson('/api/v1/auth/staff-account-setup', [
            'email' => 'new.admission@grc.test',
            'code' => $setupCode,
            'name' => 'Admissions Officer Jane',
            'password' => 'NewPassword123!@#',
            'password_confirmation' => 'NewPassword123!@#',
        ]);

        $redeemResponse->assertOk()
            ->assertJsonPath('data.status', 'active');

        $staff->refresh();
        $this->assertSame(UserStatus::Active, $staff->status);
        $this->assertNotNull($staff->account_setup_completed_at);
        $this->assertSame('Admissions Officer Jane', $staff->name);
    }

    public function test_super_admin_can_invite_dean_with_college(): void
    {
        Mail::fake();
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.inv.dean@grc.test');
        $token = $this->tokenFor($admin);

        $response = $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson('/api/v1/super-admin/users/invite', [
                'email' => 'new.dean@grc.test',
                'role' => 'dean',
                'college' => 'ccs',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'dean')
            ->assertJsonPath('data.college', 'ccs');
    }

    public function test_super_admin_invite_fails_if_dean_missing_college(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.inv.fail1@grc.test');
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson('/api/v1/super-admin/users/invite', [
                'email' => 'new.dean.nocol@grc.test',
                'role' => 'dean',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.college.0', fn ($msg) => is_string($msg));
    }

    public function test_super_admin_invite_fails_if_admission_staff_has_college(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.inv.fail2@grc.test');
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson('/api/v1/super-admin/users/invite', [
                'email' => 'new.adm.col@grc.test',
                'role' => 'admission_staff',
                'college' => 'ccs',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.college.0', fn ($msg) => is_string($msg));
    }

    public function test_registrar_head_cannot_invite_admission_staff_regression(): void
    {
        $registrar = $this->makeUser(UserRole::RegistrarHead, 'reg.head.inv@grc.test');
        $token = $this->tokenFor($registrar);

        $this->withToken($token)
            ->postJson('/api/v1/staff-invitations', [
                'email' => 'forbidden.admission@grc.test',
                'role' => 'admission_staff',
            ])
            ->assertStatus(422);
    }

    public function test_super_admin_can_change_staff_role_and_tokens_are_revoked(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.chg.role@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::AdmissionStaff, 'adm.staff.chg@grc.test');
        $this->tokenFor($staff);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $response = $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$staff->id}/role", [
                'role' => 'registrar_staff',
                'reason' => 'Transferred to registrar office',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.role', 'registrar_staff')
            ->assertJsonPath('data.college', null);

        $staff->refresh();
        $this->assertSame(UserRole::RegistrarStaff, $staff->role);
        $this->assertNull($staff->college);

        // Assert staff token revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $staff->id,
        ]);

        // Assert audit log created
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_ACCOUNT_ROLE_CHANGED,
            'actor_user_id' => $admin->id,
            'auditable_id' => $staff->id,
        ]);
    }

    public function test_super_admin_can_change_staff_role_to_dean_with_college(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.chg.dean@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'reg.staff.chg@grc.test');

        $response = $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$staff->id}/role", [
                'role' => 'dean',
                'college' => 'ccs',
                'reason' => 'Appointed Dean of CCS',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.role', 'dean')
            ->assertJsonPath('data.college', 'ccs');

        $staff->refresh();
        $this->assertSame(UserRole::Dean, $staff->role);
        $this->assertSame(CollegeCode::Ccs, $staff->college);
    }

    public function test_super_admin_cannot_change_role_of_student_or_kiosk_or_super_admin(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.chg.nonstaff@grc.test');
        $adminToken = $this->tokenFor($admin);

        $student = $this->makeUser(UserRole::Student, 'student.chg@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$student->id}/role", [
                'role' => 'registrar_staff',
                'reason' => 'Invalid attempt',
            ])
            ->assertUnprocessable();

        $kiosk = $this->makeUser(UserRole::QueueKiosk, 'kiosk.chg@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$kiosk->id}/role", [
                'role' => 'registrar_staff',
                'reason' => 'Invalid attempt',
            ])
            ->assertForbidden();

        $otherAdmin = $this->makeUser(UserRole::SuperAdmin, 'other.admin.chg@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$otherAdmin->id}/role", [
                'role' => 'registrar_staff',
                'reason' => 'Invalid attempt',
            ])
            ->assertForbidden();
    }

    public function test_super_admin_cannot_change_staff_role_to_student_or_kiosk_or_super_admin(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.chg.target@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'reg.staff.target@grc.test');

        foreach (['student', 'queue_kiosk', 'super_admin'] as $disallowedRole) {
            $this->withToken($adminToken)
                ->withHeader('X-Acting-Context', 'none')
                ->patchJson("/api/v1/super-admin/users/{$staff->id}/role", [
                    'role' => $disallowedRole,
                    'reason' => 'Invalid attempt',
                ])
                ->assertUnprocessable();
        }
    }

    public function test_super_admin_role_change_fails_if_dean_missing_college(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.chg.nocol@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.nocol@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$staff->id}/role", [
                'role' => 'dean',
                'reason' => 'Appointed Dean without college',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.college.0', fn ($msg) => is_string($msg));
    }

    public function test_super_admin_role_change_fails_if_faculty_has_active_sections(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.chg.activefac@grc.test');
        $adminToken = $this->tokenFor($admin);

        $faculty = $this->makeUser(UserRole::Faculty, 'fac.active.sec@grc.test', CollegeCode::Ccs);

        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Intro to CS', 'units' => 3, 'status' => SubjectStatus::Active]);
        Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'CS101-A',
            'professor_id' => $faculty->id,
            'capacity' => 40,
            'status' => SectionStatus::Published,
        ]);

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$faculty->id}/role", [
                'role' => 'admission_staff',
                'reason' => 'Transfer to admission',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.message', 'Reassign their sections first.');
    }

    public function test_super_admin_role_change_requires_reason(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.chg.noreason@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.noreason@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$staff->id}/role", [
                'role' => 'admission_staff',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.reason.0', fn ($msg) => is_string($msg));
    }

    public function test_super_admin_can_deactivate_staff_account_and_revoke_tokens(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.deact@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.deact@grc.test');
        $this->tokenFor($staff);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $response = $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$staff->id}/status", [
                'status' => 'disabled',
                'reason' => 'Left institution',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.status', 'disabled');

        $staff->refresh();
        $this->assertSame(UserStatus::Disabled, $staff->status);

        // Tokens revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $staff->id,
        ]);

        // Audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_ACCOUNT_DEACTIVATED,
            'actor_user_id' => $admin->id,
            'auditable_id' => $staff->id,
        ]);
    }

    public function test_super_admin_can_reactivate_deactivated_account(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.react@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.react@grc.test', null, UserStatus::Disabled);

        $response = $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$staff->id}/status", [
                'status' => 'active',
                'reason' => 'Returned from leave',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.status', 'active');

        $staff->refresh();
        $this->assertSame(UserStatus::Active, $staff->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_ACCOUNT_REACTIVATED,
            'actor_user_id' => $admin->id,
            'auditable_id' => $staff->id,
        ]);
    }

    public function test_super_admin_cannot_reactivate_pending_setup_account(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.react.pend@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.react.pend@grc.test', null, UserStatus::Disabled);
        $staff->account_setup_completed_at = null;
        $staff->save();

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$staff->id}/status", [
                'status' => 'active',
                'reason' => 'Attempting activation without setup',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.message', 'Resend the setup invitation instead.');
    }

    public function test_super_admin_status_update_requires_reason(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.status.noreason@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.status.noreason@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$staff->id}/status", [
                'status' => 'disabled',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.reason.0', fn ($msg) => is_string($msg));
    }

    public function test_super_admin_cannot_change_kiosk_or_super_admin_status(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.status.guard@grc.test');
        $adminToken = $this->tokenFor($admin);

        $kiosk = $this->makeUser(UserRole::QueueKiosk, 'kiosk.status.guard@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$kiosk->id}/status", [
                'status' => 'disabled',
                'reason' => 'Attempted deactivation',
            ])
            ->assertForbidden();

        $otherAdmin = $this->makeUser(UserRole::SuperAdmin, 'other.admin.status@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->patchJson("/api/v1/super-admin/users/{$otherAdmin->id}/status", [
                'status' => 'disabled',
                'reason' => 'Attempted deactivation',
            ])
            ->assertForbidden();
    }

    public function test_super_admin_can_resend_staff_setup_invitation(): void
    {
        Mail::fake();
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.resend.staff@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.resend@grc.test', null, UserStatus::Disabled);
        $staff->account_setup_completed_at = null;
        $staff->save();

        $response = $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson("/api/v1/super-admin/users/{$staff->id}/setup-invitation");

        $response->assertOk()
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.pending_setup', true);

        Mail::assertSent(StaffAccountSetupMail::class, fn ($mail) => $mail->hasTo('staff.resend@grc.test'));
    }

    public function test_super_admin_can_resend_student_setup_invitation(): void
    {
        Mail::fake();
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.resend.stud@grc.test');
        $adminToken = $this->tokenFor($admin);

        $student = $this->makeUser(UserRole::Student, 'student.resend@grc.test', null, UserStatus::Disabled);
        $student->account_setup_completed_at = null;
        $student->save();

        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'effective_start_year' => 2026,
            'effective_end_year' => 2030, 'status' => CurriculumStatus::Active,
        ]);
        StudentProfile::create([
            'user_id' => $student->id,
            'student_number' => '2026-00001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2026,
            'year_level' => 1,
            'student_type' => StudentType::Transferee,
            'admission_status' => AdmissionStatus::Pending,
            'academic_standing' => AcademicStanding::Good,
            'financial_status' => FinancialStatus::Payee,
            'address' => '123 Main St',
        ]);

        $response = $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson("/api/v1/super-admin/users/{$student->id}/setup-invitation");

        $response->assertOk()
            ->assertJsonPath('data.id', $student->id)
            ->assertJsonPath('data.pending_setup', true);

        Mail::assertSent(StudentAccountSetupMail::class, fn ($mail) => $mail->hasTo('student.resend@grc.test'));
    }

    public function test_super_admin_cannot_resend_setup_invitation_to_already_setup_account(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.resend.fail@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.resend.active@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson("/api/v1/super-admin/users/{$staff->id}/setup-invitation")
            ->assertUnprocessable();
    }

    public function test_super_admin_cannot_resend_setup_invitation_to_kiosk_or_super_admin(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.resend.guard@grc.test');
        $adminToken = $this->tokenFor($admin);

        $kiosk = $this->makeUser(UserRole::QueueKiosk, 'kiosk.resend.guard@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson("/api/v1/super-admin/users/{$kiosk->id}/setup-invitation")
            ->assertForbidden();

        $otherAdmin = $this->makeUser(UserRole::SuperAdmin, 'other.admin.resend@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson("/api/v1/super-admin/users/{$otherAdmin->id}/setup-invitation")
            ->assertForbidden();
    }

    public function test_super_admin_can_send_password_reset_code_to_active_account(): void
    {
        Mail::fake();
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.pw.reset@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.pw.reset@grc.test');

        $response = $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson("/api/v1/super-admin/users/{$staff->id}/password-reset");

        $response->assertOk()
            ->assertJsonPath('data.user_id', $staff->id)
            ->assertJsonPath('data.status', 'sent');

        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo('staff.pw.reset@grc.test'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_ACCOUNT_PASSWORD_RESET_SENT,
            'actor_user_id' => $admin->id,
            'auditable_id' => $staff->id,
        ]);
    }

    public function test_super_admin_cannot_send_password_reset_to_disabled_account(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.pw.dis@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.pw.dis@grc.test', null, UserStatus::Disabled);

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson("/api/v1/super-admin/users/{$staff->id}/password-reset")
            ->assertUnprocessable();
    }

    public function test_super_admin_cannot_send_password_reset_to_kiosk_or_super_admin(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.pw.guard@grc.test');
        $adminToken = $this->tokenFor($admin);

        $kiosk = $this->makeUser(UserRole::QueueKiosk, 'kiosk.pw.guard@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson("/api/v1/super-admin/users/{$kiosk->id}/password-reset")
            ->assertForbidden();

        $otherAdmin = $this->makeUser(UserRole::SuperAdmin, 'other.admin.pw@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson("/api/v1/super-admin/users/{$otherAdmin->id}/password-reset")
            ->assertForbidden();
    }

    public function test_super_admin_password_reset_is_rate_limited(): void
    {
        Mail::fake();
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.pw.rate@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.pw.rate@grc.test');

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($adminToken)
                ->withHeader('X-Acting-Context', 'none')
                ->postJson("/api/v1/super-admin/users/{$staff->id}/password-reset")
                ->assertOk();
        }

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->postJson("/api/v1/super-admin/users/{$staff->id}/password-reset")
            ->assertStatus(429);
    }

    public function test_super_admin_can_revoke_user_sessions(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.rev.sess@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.rev.sess@grc.test');
        $this->tokenFor($staff);

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $response = $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->deleteJson("/api/v1/super-admin/users/{$staff->id}/sessions");

        $response->assertOk()
            ->assertJsonPath('data.user_id', $staff->id)
            ->assertJsonPath('data.sessions_revoked', true);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $staff->id,
        ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_ACCOUNT_SESSIONS_REVOKED,
            'actor_user_id' => $admin->id,
            'auditable_id' => $staff->id,
        ]);
    }

    public function test_super_admin_cannot_revoke_sessions_of_kiosk_or_super_admin(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.rev.guard@grc.test');
        $adminToken = $this->tokenFor($admin);

        $kiosk = $this->makeUser(UserRole::QueueKiosk, 'kiosk.rev.guard@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->deleteJson("/api/v1/super-admin/users/{$kiosk->id}/sessions")
            ->assertForbidden();

        $otherAdmin = $this->makeUser(UserRole::SuperAdmin, 'other.admin.rev@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->deleteJson("/api/v1/super-admin/users/{$otherAdmin->id}/sessions")
            ->assertForbidden();
    }

    public function test_super_admin_can_permanently_delete_never_activated_unused_account(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.del.ok@grc.test');
        $adminToken = $this->tokenFor($admin);

        $chair = $this->makeUser(UserRole::ProgramChair, 'chair.del@grc.test', CollegeCode::Ccs, UserStatus::Disabled);
        $chair->account_setup_completed_at = null;
        $chair->save();

        $targetId = $chair->id;

        $response = $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->deleteJson("/api/v1/super-admin/users/{$targetId}");

        $response->assertOk()
            ->assertJsonPath('data.user_id', $targetId)
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('users', ['id' => $targetId]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::USER_ACCOUNT_DELETED,
            'actor_user_id' => $admin->id,
            'auditable_id' => $targetId,
        ]);
    }

    public function test_cannot_delete_account_that_completed_setup(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.del.setup@grc.test');
        $adminToken = $this->tokenFor($admin);

        $staff = $this->makeUser(UserRole::RegistrarStaff, 'staff.del.setup@grc.test');
        $this->assertNotNull($staff->account_setup_completed_at);

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->deleteJson("/api/v1/super-admin/users/{$staff->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.message', 'Deactivate instead — this account has history.');

        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_cannot_delete_account_with_faculty_availabilities(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.del.avail@grc.test');
        $adminToken = $this->tokenFor($admin);

        $faculty = $this->makeUser(UserRole::Faculty, 'faculty.del.avail@grc.test', CollegeCode::Ccs, UserStatus::Disabled);
        $faculty->account_setup_completed_at = null;
        $faculty->save();

        FacultyAvailability::create([
            'professor_id' => $faculty->id,
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
            'origin' => 'test',
        ]);

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->deleteJson("/api/v1/super-admin/users/{$faculty->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.message', 'Deactivate instead — this account has history.');

        $this->assertDatabaseHas('users', ['id' => $faculty->id]);
    }

    public function test_cannot_delete_student_account(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.del.stud@grc.test');
        $adminToken = $this->tokenFor($admin);

        $student = $this->makeUser(UserRole::Student, 'student.del@grc.test', null, UserStatus::Disabled);
        $student->account_setup_completed_at = null;
        $student->save();

        $program = Program::create(['code' => 'BSCS2', 'name' => 'BS Computer Science 2', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum 2',
            'effective_school_year' => '2026-2027', 'effective_start_year' => 2026,
            'effective_end_year' => 2030, 'status' => CurriculumStatus::Active,
        ]);
        StudentProfile::create([
            'user_id' => $student->id,
            'student_number' => '2026-00002',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2026,
            'year_level' => 1,
            'student_type' => StudentType::Freshman,
            'admission_status' => AdmissionStatus::Pending,
            'academic_standing' => AcademicStanding::Good,
            'financial_status' => FinancialStatus::Payee,
            'address' => '123 Main St',
        ]);

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->deleteJson("/api/v1/super-admin/users/{$student->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.message', 'Deactivate instead — this account has history.');

        $this->assertDatabaseHas('users', ['id' => $student->id]);
    }

    public function test_cannot_delete_account_that_acted_in_audit_logs(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.del.audit@grc.test');
        $adminToken = $this->tokenFor($admin);

        $user = $this->makeUser(UserRole::RegistrarStaff, 'staff.del.audit@grc.test', null, UserStatus::Disabled);
        $user->account_setup_completed_at = null;
        $user->save();

        AuditLog::create([
            'actor_user_id' => $user->id,
            'action' => AuditAction::USER_ACCOUNT_LIST_VIEWED,
            'auditable_type' => 'user_account',
            'auditable_id' => null,
            'request_id' => 'req-test-123',
            'ip_address' => '127.0.0.1',
        ]);

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->deleteJson("/api/v1/super-admin/users/{$user->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.message', 'Deactivate instead — this account has history.');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_super_admin_cannot_delete_kiosk_or_super_admin(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.del.guard@grc.test');
        $adminToken = $this->tokenFor($admin);

        $kiosk = $this->makeUser(UserRole::QueueKiosk, 'kiosk.del.guard@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->deleteJson("/api/v1/super-admin/users/{$kiosk->id}")
            ->assertForbidden();

        $otherAdmin = $this->makeUser(UserRole::SuperAdmin, 'other.admin.del@grc.test');

        $this->withToken($adminToken)
            ->withHeader('X-Acting-Context', 'none')
            ->deleteJson("/api/v1/super-admin/users/{$otherAdmin->id}")
            ->assertForbidden();
    }
}
