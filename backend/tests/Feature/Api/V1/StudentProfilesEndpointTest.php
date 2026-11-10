<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Mail\StudentAccountSetupMail;
use App\Models\AcademicTerm;
use App\Models\AdmissionRequirementType;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentAdmissionRequirement;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class StudentProfilesEndpointTest extends TestCase
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

    /**
     * `ProvisionStudent`/`UpdateStudentProfile` derive `entry_year` from the
     * current ongoing academic term (Stakeholder Doc 17) instead of trusting
     * the client — every test that expects provisioning to actually succeed
     * needs one of these set up first.
     */
    private function setCurrentTerm(string $schoolYear): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => $schoolYear,
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    /** @return array{0: Program, 1: Curriculum} */
    private function makeProgramAndCurriculum(): array
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'effective_start_year' => 2026,
            'effective_end_year' => 2030, 'status' => CurriculumStatus::Active,
        ]);

        return [$program, $curriculum];
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/student-profile')->assertUnauthorized();
        $this->postJson('/api/v1/student-profiles', [])->assertUnauthorized();
    }

    public function test_admission_staff_can_provision_a_student(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'Asia/Manila'));
        [$program, $curriculum] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.provision@grc.test');
        Mail::fake();

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'New',
            'last_name' => 'Student',
            'email' => 'new.student@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '123 Test Street, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'transferee',
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.student_number', '2027-08-00001');
        $response->assertJsonPath('data.address', '123 Test Street, Caloocan City, Caloocan City, Metro Manila');
        $response->assertJsonPath('data.gender', 'F');
        $response->assertJsonPath('data.birth_date', '2006-05-04');
        $response->assertJsonPath('data.citizenship', 'Filipino');
        $response->assertJsonPath('data.contact_number', '09171234567');
        $response->assertJsonPath('data.street_barangay', '123 Test Street, Caloocan City');
        $response->assertJsonPath('data.town_city', 'Caloocan City');
        $response->assertJsonPath('data.province', 'Metro Manila');
        $response->assertJsonPath('data.generated_fields', []);
        $response->assertJsonPath('data.entry_year', 2027);
        // Admission no longer chooses it: every new account starts Regular and the system re-derives it.
        $response->assertJsonPath('data.enrollment_category', 'regular');
        $response->assertJsonPath('data.student_type', 'transferee');
        $response->assertJsonPath('data.admission_status', 'admitted');
        $response->assertJsonPath('data.account_setup_status', 'pending');
        $response->assertJsonPath('data.invitation_delivery_status', 'sent');
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.setup_code');

        $this->assertDatabaseHas('users', [
            'email' => 'new.student@grc.test',
            'role' => 'student',
            'status' => 'disabled',
            'account_setup_completed_at' => null,
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'student_number' => '2027-08-00001',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '123 Test Street, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'address' => '123 Test Street, Caloocan City, Caloocan City, Metro Manila',
            'demographics_demo_fields' => null,
            'requirements_verified_by' => User::query()->where('email', 'admission.provision@grc.test')->value('id'),
        ]);
        Mail::assertSentCount(1);
        $provisioningAudit = AuditLog::query()->where('action', AuditAction::STUDENT_PROFILE_PROVISIONED)->sole();
        $provisioningPayload = json_encode([$provisioningAudit->before_values, $provisioningAudit->after_values], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('New Student', $provisioningPayload);
        self::assertStringNotContainsString('new.student@grc.test', $provisioningPayload);
        self::assertStringNotContainsString('123 Test Street', $provisioningPayload);
        self::assertStringNotContainsString('09171234567', $provisioningPayload);
        self::assertStringNotContainsString('2006-05-04', $provisioningPayload);
        $invitationAudit = AuditLog::query()->where('action', AuditAction::STUDENT_ACCOUNT_SETUP_INVITATION_SENT)->sole();
        $invitationPayload = json_encode([$invitationAudit->before_values, $invitationAudit->after_values], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('new.student@grc.test', $invitationPayload);
    }

    /**
     * @return list<int>
     */
    private function applicableRequirementIds(string $studentCategory): array
    {
        return AdmissionRequirementType::query()
            ->where('is_active', true)
            ->whereIn('category', [$studentCategory, 'additional'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $requirementTypeIds
     * @return array<string, mixed>
     */
    private function provisionPayload(Program $program, string $email, array $requirementTypeIds, int $yearLevel = 1, string $studentType = 'freshman'): array
    {
        return [
            'student_type' => $studentType,
            'first_name' => 'Checklist',
            'last_name' => 'Student',
            'email' => $email,
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '123 Test Street, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => $yearLevel,
            'requirement_type_ids' => $requirementTypeIds,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
        ];
    }

    public function test_provisioning_with_the_requirements_checklist_records_every_requirement_as_submitted(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.checklist@grc.test');
        Mail::fake();
        $ids = $this->applicableRequirementIds('freshman');

        $response = $this->withToken($token)->postJson(
            '/api/v1/student-profiles',
            $this->provisionPayload($program, 'checklist.student@grc.test', $ids),
        );

        $response->assertCreated();
        $profileId = (int) $response->json('data.id');
        self::assertSame(
            count($ids),
            StudentAdmissionRequirement::query()->where('student_profile_id', $profileId)->where('is_submitted', true)->count(),
        );
        // The same rows the checklist screen reads: nothing is left missing for the new student.
        $this->withToken($token)->getJson("/api/v1/student-profiles/{$profileId}/admission-requirements")
            ->assertOk()
            ->assertJsonPath('data.summary.complete', true)
            ->assertJsonPath('data.summary.missing_count', 0);
    }

    public function test_provisioning_may_go_ahead_with_requirements_still_missing_and_records_only_the_ticked_ones(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.checklist.partial@grc.test');
        Mail::fake();
        $ids = $this->applicableRequirementIds('freshman');
        array_pop($ids);

        $response = $this->withToken($token)->postJson(
            '/api/v1/student-profiles',
            $this->provisionPayload($program, 'checklist.partial@grc.test', $ids),
        );

        $response->assertCreated();
        $profileId = (int) $response->json('data.id');
        self::assertSame(
            count($ids),
            StudentAdmissionRequirement::query()->where('student_profile_id', $profileId)->where('is_submitted', true)->count(),
        );
        // Not everything was handed in, so the account is not marked "requirements verified".
        $this->assertDatabaseHas('student_profiles', [
            'id' => $profileId,
            'requirements_verified_at' => null,
            'requirements_verified_by' => null,
        ]);
        // The missing one stays on the student's checklist for Admission to tick later.
        $this->withToken($token)->getJson("/api/v1/student-profiles/{$profileId}/admission-requirements")
            ->assertOk()
            ->assertJsonPath('data.summary.complete', false)
            ->assertJsonPath('data.summary.missing_count', 1);
    }

    public function test_provisioning_may_go_ahead_with_no_requirement_ticked_at_all(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.checklist.none@grc.test');
        Mail::fake();

        $response = $this->withToken($token)->postJson(
            '/api/v1/student-profiles',
            $this->provisionPayload($program, 'checklist.none@grc.test', []),
        );

        $response->assertCreated();
        self::assertSame(0, StudentAdmissionRequirement::query()->where('student_profile_id', $response->json('data.id'))->count());
    }

    public function test_a_complete_checklist_marks_the_requirements_verified(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.checklist.verified@grc.test');
        Mail::fake();

        $response = $this->withToken($token)->postJson(
            '/api/v1/student-profiles',
            $this->provisionPayload($program, 'checklist.verified@grc.test', $this->applicableRequirementIds('transferee'), 2, 'transferee'),
        );

        $response->assertCreated();
        $this->assertDatabaseHas('student_profiles', [
            'id' => (int) $response->json('data.id'),
            'student_type' => 'transferee',
            'requirements_verified_by' => User::query()->where('email', 'admission.checklist.verified@grc.test')->value('id'),
        ]);
        self::assertNotNull(StudentProfile::query()->findOrFail($response->json('data.id'))->requirements_verified_at);
    }

    public function test_provisioning_refuses_a_requirement_that_does_not_apply_to_the_student_type(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.checklist.wrongtype@grc.test');
        // A Freshman is not asked for Transferee requirements, so ticking one is refused.
        $ids = [...$this->applicableRequirementIds('freshman'), ...$this->applicableRequirementIds('transferee')];

        $this->withToken($token)->postJson(
            '/api/v1/student-profiles',
            $this->provisionPayload($program, 'checklist.wrongtype@grc.test', array_values(array_unique($ids))),
        )->assertUnprocessable()->assertJsonPath('error.errors.requirement_type_ids.0', 'One of the checked requirements does not apply to this student.');

        $this->assertDatabaseMissing('users', ['email' => 'checklist.wrongtype@grc.test']);
    }

    public function test_provisioning_normalizes_name_casing_regardless_of_how_admission_typed_it(): void
    {
        [$program, $curriculum] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.casing@grc.test');
        Mail::fake();

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'juan',
            'middle_name' => 'mendoza',
            'last_name' => 'DELA CRUZ',
            'suffix' => 'iii',
            'email' => 'casing.student@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '1 Casing Street, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'freshman',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Juan M. Dela Cruz III');
        $response->assertJsonPath('data.first_name', 'Juan');
        $response->assertJsonPath('data.middle_name', 'Mendoza');
        $response->assertJsonPath('data.middle_initial', 'M');
        $response->assertJsonPath('data.last_name', 'Dela Cruz');
        $response->assertJsonPath('data.suffix', 'III');

        $this->assertDatabaseHas('users', [
            'email' => 'casing.student@grc.test',
            'name' => 'Juan M. Dela Cruz III',
            'first_name' => 'Juan',
            'middle_name' => 'Mendoza',
            'middle_initial' => 'M',
            'last_name' => 'Dela Cruz',
            'suffix' => 'III',
        ]);
    }

    public function test_provisioning_requires_verified_requirements_and_the_students_details(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.requirements@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'Incomplete',
            'last_name' => 'Applicant',
            'email' => 'incomplete.applicant@grc.test',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => false,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'error' => ['errors' => [
                    'gender', 'birth_date', 'citizenship', 'contact_number',
                    'street_barangay', 'town_city', 'province', 'requirements_verified',
                ]],
            ]);
        $this->assertDatabaseMissing('users', ['email' => 'incomplete.applicant@grc.test']);
    }

    public function test_provisioning_rejects_client_overrides_of_server_controlled_fields(): void
    {
        [$program, $curriculum] = $this->makeProgramAndCurriculum();
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.contract@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'Unsafe',
            'last_name' => 'Contract Student',
            'email' => 'unsafe.contract@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '789 Contract Road, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'password' => 'client-chosen-password',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2027,
            'year_level' => 1,
            'enrollment_category' => 'irregular',
            'address' => 'A single address text a client made up',
            'student_type' => 'freshman',
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
        ]);

        // Admission chooses the student type, but the Regular / Irregular category is decided by the
        // system, and the address is written from the street, town and province: all refused.
        $response->assertUnprocessable()
            ->assertJsonStructure([
                'error' => ['errors' => ['password', 'curriculum_id', 'entry_year', 'enrollment_category', 'address']],
            ]);
        $this->assertArrayNotHasKey('student_type', $response->json('error.errors'));
        $this->assertDatabaseMissing('users', ['email' => 'unsafe.contract@grc.test']);
    }

    public function test_student_activates_the_pending_account_with_the_emailed_one_time_code(): void
    {
        config(['app.frontend_url' => 'http://localhost:3000']);
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.activation@grc.test');
        Mail::fake();

        $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'Pending',
            'last_name' => 'Student',
            'email' => 'pending.student@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '456 Setup Avenue, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'transferee',
        ])->assertCreated();

        $setupCode = null;
        Mail::assertSent(StudentAccountSetupMail::class, function (StudentAccountSetupMail $mail) use (&$setupCode): bool {
            $setupCode = $mail->setupCode;

            return $mail->setupUrl === 'http://localhost:3000/account-setup'
                && ! str_contains($mail->setupUrl, 'token=');
        });
        self::assertIsString($setupCode);
        self::assertNotSame('', $setupCode);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'pending.student@grc.test',
            'password' => 'New-Secure-Password1!',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/account-setup', [
            'email' => 'pending.student@grc.test',
            'code' => $setupCode,
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ])->assertOk()
            ->assertJsonPath('data.type', 'account-setup')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseHas('users', [
            'email' => 'pending.student@grc.test',
            'status' => 'active',
        ]);
        self::assertNotNull(User::query()->where('email', 'pending.student@grc.test')->value('account_setup_completed_at'));
        $this->assertDatabaseCount('account_setup_codes', 0);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::STUDENT_ACCOUNT_ACTIVATED)->count());

        $this->postJson('/api/v1/auth/login', [
            'email' => 'pending.student@grc.test',
            'password' => 'New-Secure-Password1!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/account-setup', [
            'email' => 'pending.student@grc.test',
            'code' => $setupCode,
            'password' => 'Another-Secure-Password1!',
            'password_confirmation' => 'Another-Secure-Password1!',
        ])->assertUnprocessable();
    }

    public function test_an_expired_or_invalid_setup_code_cannot_activate_the_account(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.expiry@grc.test');
        Mail::fake();

        $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'Expiring',
            'last_name' => 'Student',
            'email' => 'expiring.student@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '60 Minute Avenue, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'transferee',
        ])->assertCreated();

        $setupCode = null;
        Mail::assertSent(StudentAccountSetupMail::class, function (StudentAccountSetupMail $mail) use (&$setupCode): bool {
            $setupCode = $mail->setupCode;

            return true;
        });
        DB::table('account_setup_codes')
            ->whereIn('user_id', User::query()->where('email', 'expiring.student@grc.test')->pluck('id'))
            ->update(['expires_at' => now()->subMinute()]);

        foreach ([$setupCode, '123456'] as $code) {
            $this->postJson('/api/v1/auth/account-setup', [
                'email' => 'expiring.student@grc.test',
                'code' => $code,
                'password' => 'New-Secure-Password1!',
                'password_confirmation' => 'New-Secure-Password1!',
            ])->assertUnprocessable()
                ->assertJsonPath('error.errors.code.0', 'The setup code is invalid or expired.');
        }

        $this->assertDatabaseHas('users', [
            'email' => 'expiring.student@grc.test',
            'status' => 'disabled',
            'account_setup_completed_at' => null,
        ]);
    }

    public function test_mail_failure_keeps_one_pending_account_and_exposes_a_resendable_delivery_state(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.mail-failure@grc.test');
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('Simulated mail transport failure.'));

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'Mail Failure',
            'last_name' => 'Student',
            'email' => 'mail.failure.student@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => 'Retry Street, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'transferee',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.account_setup_status', 'pending')
            ->assertJsonPath('data.invitation_delivery_status', 'failed');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', [
            'email' => 'mail.failure.student@grc.test',
            'status' => 'disabled',
        ]);
        $this->assertDatabaseCount('account_setup_codes', 1);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::STUDENT_ACCOUNT_SETUP_INVITATION_FAILED)->count());
    }

    public function test_an_existing_student_number_must_match_the_yyyy_mm_nnnnn_format(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.badformat@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            ...$this->provisionPayload($program, 'badformat.student@grc.test', [], 1, 'returnee'),
            'has_existing_student_number' => true,
            'student_number' => 'STU-2027-0001',
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('student_number', $response->json('error.errors'));
        $this->assertDatabaseMissing('users', ['email' => 'badformat.student@grc.test']);
    }

    public function test_financial_status_is_accepted_and_defaults_to_null(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.financial@grc.test');
        Mail::fake();

        $scholar = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'Scholar',
            'last_name' => 'Student',
            'email' => 'scholar.student@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '101 Scholar Avenue, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'financial_status' => 'scholar',
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'freshman',
        ]);
        $scholar->assertCreated();
        $scholar->assertJsonPath('data.financial_status', 'scholar');
        $scholar->assertJsonPath('data.financial_status_label', 'Scholar');

        $unset = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'Unset',
            'last_name' => 'Student',
            'email' => 'unset.student@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '102 Default Avenue, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'freshman',
        ]);
        $unset->assertCreated();
        $unset->assertJsonPath('data.financial_status', null);
        $unset->assertJsonPath('data.financial_status_label', null);
    }

    /**
     * Admission chooses the Student Type; the Regular / Irregular category is no longer asked for
     * because the system detects it (the owner's review of 2026-10-15). A new account starts Regular
     * and `enrollment_category_derived_at` stays NULL until the grade-based re-derivation (ADR 0021)
     * or the approval of a transferee's credits changes it.
     */
    public function test_the_student_type_is_what_admission_chose_and_the_category_starts_regular(): void
    {
        $program = Program::create(['code' => 'BSUNI', 'name' => 'BS Universal', 'status' => ProgramStatus::Active]);
        Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSUNI Curriculum',
            'effective_school_year' => '2000-2001', 'effective_start_year' => 2000,
            'effective_end_year' => null, 'status' => CurriculumStatus::Active,
        ]);
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.chosen@grc.test');
        Mail::fake();

        foreach ([['transferee', 'Transferee'], ['returnee', 'Returnee'], ['existing_student', 'Existing Student']] as $index => [$type, $label]) {
            $email = "chosen-{$index}@grc.test";
            $payload = $this->provisionPayload($program, $email, [], 1, $type);
            $response = $this->withToken($token)->postJson('/api/v1/student-profiles', $payload);

            $response->assertCreated();
            $response->assertJsonPath('data.enrollment_category', 'regular');
            $response->assertJsonPath('data.student_type', $type);
            $response->assertJsonPath('data.student_type_label', $label);
            $this->assertDatabaseHas('student_profiles', [
                'user_id' => User::query()->where('email', $email)->value('id'),
                'enrollment_category' => 'regular',
                'student_type' => $type,
                'enrollment_category_derived_at' => null,
            ]);
        }
    }

    public function test_the_student_type_is_required_and_a_category_is_refused(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.required.choice@grc.test');
        $payload = $this->provisionPayload($program, 'required.choice@grc.test', []);
        unset($payload['student_type']);

        $missing = $this->withToken($token)->postJson('/api/v1/student-profiles', $payload);
        $missing->assertUnprocessable();
        self::assertArrayHasKey('student_type', $missing->json('error.errors'));
        self::assertArrayNotHasKey('enrollment_category', $missing->json('error.errors'));

        $unknown = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            ...$payload,
            'student_type' => 'visitor',
            'enrollment_category' => 'regular',
        ]);
        $unknown->assertUnprocessable();
        self::assertArrayHasKey('student_type', $unknown->json('error.errors'));
        self::assertArrayHasKey('enrollment_category', $unknown->json('error.errors'));
        $this->assertDatabaseMissing('users', ['email' => 'required.choice@grc.test']);
    }

    public function test_the_server_assigns_the_next_student_number_in_the_manila_year_and_month(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'Asia/Manila'));
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.sequence@grc.test');
        Mail::fake();

        $first = $this->withToken($token)->postJson(
            '/api/v1/student-profiles',
            $this->provisionPayload($program, 'sequence.one@grc.test', []),
        );
        $second = $this->withToken($token)->postJson(
            '/api/v1/student-profiles',
            $this->provisionPayload($program, 'sequence.two@grc.test', []),
        );

        $first->assertCreated()->assertJsonPath('data.student_number', '2027-08-00001');
        $second->assertCreated()->assertJsonPath('data.student_number', '2027-08-00002');
        $this->assertDatabaseHas('student_number_sequences', ['year' => 2027, 'last_value' => 2]);
    }

    public function test_a_student_number_sent_without_the_existing_number_option_is_refused(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.oldclient@grc.test');

        // An older frontend still sends the random number it made itself.
        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            ...$this->provisionPayload($program, 'oldclient.student@grc.test', []),
            'student_number' => '2027-08-12345',
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('student_number', $response->json('error.errors'));
        $this->assertDatabaseMissing('users', ['email' => 'oldclient.student@grc.test']);
        $this->assertDatabaseCount('student_number_sequences', 0);
    }

    public function test_a_returnee_an_existing_student_or_a_transferee_can_keep_the_number_they_already_have(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'Asia/Manila'));
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.existingnumber@grc.test');
        Mail::fake();

        // A transferee's number from the school they came from is entered too (2026-10-04).
        $existing = [['returnee', '2026-06-00123'], ['existing_student', '2026-06-00124'], ['transferee', '2026-06-00125']];
        foreach ($existing as $index => [$type, $number]) {
            $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
                ...$this->provisionPayload($program, "existing-number-{$index}@grc.test", [], 1, $type),
                'has_existing_student_number' => true,
                'student_number' => $number,
            ]);

            $response->assertCreated()->assertJsonPath('data.student_number', $number);
        }

        // The running counter was never asked for a number.
        $this->assertDatabaseCount('student_number_sequences', 0);
        $sources = AuditLog::query()
            ->where('action', AuditAction::STUDENT_PROFILE_PROVISIONED)
            ->get()
            ->map(fn (AuditLog $audit): mixed => $audit->after_values['student_number_source'])
            ->all();
        self::assertSame(['existing', 'existing', 'existing'], $sources);
    }

    public function test_the_next_student_number_is_previewed_without_being_reserved(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'Asia/Manila'));
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.preview@grc.test');
        Mail::fake();

        $this->withToken($token)->getJson('/api/v1/student-profiles/next-student-number')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.student_number', '2027-08-00001');
        // Looking is not taking: the running counter was not written.
        $this->assertDatabaseCount('student_number_sequences', 0);

        $this->withToken($token)->postJson('/api/v1/student-profiles', $this->provisionPayload($program, 'preview-first@grc.test', [], 1, 'transferee'))
            ->assertCreated()
            ->assertJsonPath('data.student_number', '2027-08-00001');

        $this->withToken($token)->getJson('/api/v1/student-profiles/next-student-number')
            ->assertJsonPath('data.student_number', '2027-08-00002');
    }

    public function test_an_existing_student_number_is_refused_for_a_freshman(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.existingrefused@grc.test');

        foreach (['freshman'] as $type) {
            $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
                ...$this->provisionPayload($program, "refused-{$type}@grc.test", [], 1, $type),
                'has_existing_student_number' => true,
                'student_number' => '2026-06-00200',
            ]);

            $response->assertUnprocessable()->assertJsonPath(
                'error.errors.has_existing_student_number.0',
                'Only a Transferee, a Returnee or an Existing Student can already have a student number.',
            );
            $this->assertDatabaseMissing('users', ['email' => "refused-{$type}@grc.test"]);
        }
    }

    public function test_an_existing_student_number_that_is_already_in_use_is_refused(): void
    {
        [$program, $curriculum] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $owner = User::create(['name' => 'Owner', 'email' => 'owner.number@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active]);
        StudentProfile::create([
            'user_id' => $owner->id, 'student_number' => '2026-06-00300', 'program_id' => $program->id,
            'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => 'admitted', 'academic_standing' => 'good',
        ]);
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.existingduplicate@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            ...$this->provisionPayload($program, 'duplicate.number@grc.test', [], 1, 'returnee'),
            'has_existing_student_number' => true,
            'student_number' => '2026-06-00300',
        ]);

        $response->assertUnprocessable()->assertJsonPath(
            'error.errors.student_number.0',
            'This student number is already in use.',
        );
        $this->assertDatabaseMissing('users', ['email' => 'duplicate.number@grc.test']);
    }

    public function test_the_existing_number_option_needs_the_number(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.existingmissing@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            ...$this->provisionPayload($program, 'missing.number@grc.test', [], 1, 'existing_student'),
            'has_existing_student_number' => true,
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('student_number', $response->json('error.errors'));
    }

    public function test_a_non_admission_staff_role_cannot_provision_a_student(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $token = $this->tokenFor(UserRole::RegistrarStaff, 'registrar.provision@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'New',
            'last_name' => 'Student',
            'email' => 'blocked.student@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '103 Blocked Avenue, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'entry_year' => 2027,
            'year_level' => 1,
            'student_type' => 'freshman',
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseMissing('users', ['email' => 'blocked.student@grc.test']);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_provisioning_fails_cleanly_when_no_curriculum_covers_the_entry_year(): void
    {
        $program = Program::create([
            'code' => 'BSEMPTY',
            'name' => 'Program Without Curriculum',
            'status' => ProgramStatus::Active,
        ]);
        $this->setCurrentTerm('2035-2036');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.mismatch@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'New',
            'last_name' => 'Student',
            'email' => 'mismatch.student@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '104 Missing Curriculum Road, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'freshman',
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertDatabaseMissing('users', ['email' => 'mismatch.student@grc.test']);
    }

    public function test_provisioning_resolves_the_curriculum_from_the_current_terms_entry_year(): void
    {
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active]);
        $oldCurriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSIT 2018 Curriculum',
            'effective_school_year' => '2018-2019',
            'effective_start_year' => 2018,
            'effective_end_year' => 2023,
            'status' => CurriculumStatus::Archived,
        ]);
        Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSIT 2024 Curriculum',
            'effective_school_year' => '2024-2025',
            'effective_start_year' => 2024,
            'effective_end_year' => 2029,
            'status' => CurriculumStatus::Active,
        ]);
        $this->setCurrentTerm('2023-2024');
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.automatic-curriculum@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'Fourth Year',
            'last_name' => 'Student',
            'email' => 'automatic.curriculum@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '105 Automatic Curriculum Road, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 4,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'freshman',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.curriculum_id', $oldCurriculum->id)
            ->assertJsonPath('data.entry_year', 2023)
            ->assertJsonPath('data.curriculum_name', 'BSIT 2018 Curriculum')
            ->assertJsonPath('data.curriculum_effective_school_year', '2018-2019');
    }

    public function test_provisioning_fails_cleanly_when_no_academic_term_is_ongoing(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.noterm@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'No',
            'last_name' => 'Term',
            'email' => 'no.term.student@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '110 No Term Road, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'freshman',
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertDatabaseMissing('users', ['email' => 'no.term.student@grc.test']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        User::create(['name' => 'Existing', 'email' => 'existing@grc.test', 'password' => 'irrelevant', 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $token = $this->tokenFor(UserRole::AdmissionStaff, 'admission.dup@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', [
            'first_name' => 'New',
            'last_name' => 'Student',
            'email' => 'existing@grc.test',
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '106 Duplicate Road, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => 'freshman',
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    /**
     * Provisions the profile directly (not through the Admission Staff HTTP
     * endpoint) so this test authenticates as exactly one user — chaining a
     * second, different authenticated user in the same test method hits a
     * Sanctum guard-caching quirk documented in PROGRESS.md.
     */
    public function test_a_student_can_read_their_own_profile(): void
    {
        [$program, $curriculum] = $this->makeProgramAndCurriculum();

        $student = User::create([
            'name' => 'Reader Student', 'email' => 'reader.student@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        StudentProfile::create([
            'user_id' => $student->id, 'student_number' => 'STU-2027-0005',
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 2,
            'admission_status' => 'admitted', 'academic_standing' => 'good',
        ]);

        $studentToken = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'reader.student@grc.test',
            'password' => self::PASSWORD,
        ])->json('data.token');

        $response = $this->withToken($studentToken)->getJson('/api/v1/student-profile');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.student_number', 'STU-2027-0005');
        $response->assertJsonPath('data.year_level', 2);
    }

    public function test_a_student_never_sees_another_students_profile(): void
    {
        [$program, $curriculum] = $this->makeProgramAndCurriculum();

        $studentA = User::create(['name' => 'A', 'email' => 'student.a@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        StudentProfile::create([
            'user_id' => $studentA->id, 'student_number' => 'STU-A', 'program_id' => $program->id,
            'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => 'admitted', 'academic_standing' => 'good',
        ]);

        $studentB = User::create(['name' => 'B', 'email' => 'student.b@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        StudentProfile::create([
            'user_id' => $studentB->id, 'student_number' => 'STU-B', 'program_id' => $program->id,
            'curriculum_id' => $curriculum->id, 'year_level' => 3,
            'admission_status' => 'admitted', 'academic_standing' => 'good',
        ]);

        $tokenB = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'student.b@grc.test', 'password' => self::PASSWORD,
        ])->json('data.token');

        $response = $this->withToken($tokenB)->getJson('/api/v1/student-profile');

        $response->assertOk()->assertJsonPath('data.student_number', 'STU-B');
    }

    public function test_a_user_with_no_profile_gets_a_clean_404_not_a_500(): void
    {
        $token = $this->tokenFor(UserRole::Student, 'no.profile@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/student-profile');

        $response->assertNotFound();
    }

    public function test_account_setup_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/account-setup', [
                'email' => 'throttle.target@grc.test',
                'code' => 'not-a-real-code',
                'password' => 'irrelevant-password',
                'password_confirmation' => 'irrelevant-password',
            ])->assertUnprocessable();
        }

        $response = $this->postJson('/api/v1/auth/account-setup', [
            'email' => 'throttle.target@grc.test',
            'code' => 'not-a-real-code',
            'password' => 'irrelevant-password',
            'password_confirmation' => 'irrelevant-password',
        ]);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }
}
