<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Auth\AccountSetupCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdmissionStudentRecordsEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Program, Curriculum} */
    private function catalog(): array
    {
        $program = Program::firstOrCreate(['code' => 'BSIT'], [
            'code' => 'BSIT',
            'name' => 'Bachelor of Science in Information Technology',
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::firstOrCreate([
            'program_id' => $program->id,
            'effective_start_year' => 2026,
        ], [
            'name' => 'BSIT 2026 Curriculum',
            'effective_school_year' => '2026-2027',
            'effective_start_year' => 2026,
            'effective_end_year' => 2030,
            'status' => CurriculumStatus::Active,
        ]);

        return [$program, $curriculum];
    }

    private function user(UserRole $role, string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function student(string $name, string $email, string $studentNumber): StudentProfile
    {
        [$program, $curriculum] = $this->catalog();
        $user = $this->user(UserRole::Student, $name, $email);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $studentNumber,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2026,
            'year_level' => 1,
            'enrollment_category' => 'regular',
            'admission_status' => 'admitted',
            'academic_standing' => 'good',
            'address' => 'Original Address, Caloocan City',
        ]);
    }

    public function test_admission_can_search_students_by_name_number_or_email_and_view_the_record(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Staff', 'admission.records@grc.test');
        $aurora = $this->student('Aurora S. Lopez', 'aurora.lopez@grc.test', '2026-08-01085');
        $this->student('Another Student', 'another.student@grc.test', '2026-08-01086');
        Sanctum::actingAs($admission);

        foreach (['Aurora', '2026-08-01085', 'aurora.lopez@grc.test'] as $search) {
            $this->getJson('/api/v1/student-profiles?search='.urlencode($search))
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $aurora->id)
                ->assertJsonPath('data.0.name', 'Aurora S. Lopez')
                ->assertJsonPath('data.0.address', 'Original Address, Caloocan City')
                ->assertJsonPath('data.0.academic_setup_editable', true);
        }

        $this->getJson('/api/v1/student-profiles/'.$aurora->id)
            ->assertOk()
            ->assertJsonPath('data.student_number', '2026-08-01085')
            ->assertJsonPath('data.program_code', 'BSIT');
    }

    public function test_non_admission_roles_cannot_browse_or_view_student_records(): void
    {
        $student = $this->student('Private Student', 'private.student@grc.test', '2026-08-01087');
        Sanctum::actingAs($student->user);

        $this->getJson('/api/v1/student-profiles')->assertForbidden();
        $this->getJson('/api/v1/student-profiles/'.$student->id)->assertForbidden();
    }

    public function test_admission_can_correct_a_pre_enrollment_record_with_a_reason_and_in_person_verification(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Editor', 'admission.editor@grc.test');
        $student = $this->student('Original Student', 'original.student@grc.test', '2026-08-01088');
        $newProgram = Program::create([
            'code' => 'BSCS',
            'name' => 'Bachelor of Science in Computer Science',
            'status' => ProgramStatus::Active,
        ]);
        // Covers the student's existing entry_year (2026, set by the
        // student() helper) rather than 2027: entry_year is immutable after
        // creation (Stakeholder Doc 17 — it is derived once from the
        // ongoing term at intake, never client-supplied), so a program
        // change must resolve a curriculum against the year the student
        // already has, not a year this request could once override.
        $newCurriculum = Curriculum::create([
            'program_id' => $newProgram->id,
            'name' => 'BSCS 2026 Curriculum',
            'effective_school_year' => '2026-2027',
            'effective_start_year' => 2026,
            'effective_end_year' => 2031,
            'status' => CurriculumStatus::Active,
        ]);
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'first_name' => 'Corrected',
            'last_name' => 'Student',
            'email' => 'corrected.student@grc.test',
            'gender' => 'M',
            'birth_date' => '2005-03-02',
            'citizenship' => 'Filipino',
            'contact_number' => '+63 917 123 4567',
            'street_barangay' => 'Corrected Complete Street, Barangay 5',
            'town_city' => 'Quezon City',
            'province' => 'Metro Manila',
            'student_number' => '2027-08-01088',
            'program_id' => $newProgram->id,
            'year_level' => 2,
            'student_type' => 'transferee',
            'financial_status' => 'payee',
            'admission_status' => 'admitted',
            'reason' => 'Corrected after reviewing the submitted admission documents.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Corrected Student')
            ->assertJsonPath('data.program_id', $newProgram->id)
            ->assertJsonPath('data.curriculum_id', $newCurriculum->id)
            // The type is what Admission sent (stakeholder Doc 20); the Regular / Irregular category is the
            // system's, so a correction leaves it alone.
            ->assertJsonPath('data.enrollment_category', 'regular')
            ->assertJsonPath('data.student_type', 'transferee')
            ->assertJsonPath('data.gender', 'M')
            ->assertJsonPath('data.birth_date', '2005-03-02')
            ->assertJsonPath('data.contact_number', '+63 917 123 4567')
            ->assertJsonPath('data.address', 'Corrected Complete Street, Barangay 5, Quezon City, Metro Manila');

        $this->assertDatabaseHas('users', [
            'id' => $student->user_id,
            'name' => 'Corrected Student',
            'email' => 'corrected.student@grc.test',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'id' => $student->id,
            'student_number' => '2027-08-01088',
            'address' => 'Corrected Complete Street, Barangay 5, Quezon City, Metro Manila',
            'street_barangay' => 'Corrected Complete Street, Barangay 5',
            'town_city' => 'Quezon City',
            'province' => 'Metro Manila',
            'gender' => 'M',
            'citizenship' => 'Filipino',
            'program_id' => $newProgram->id,
            'curriculum_id' => $newCurriculum->id,
            'entry_year' => 2026,
            'year_level' => 2,
            'enrollment_category' => 'regular',
            'student_type' => 'transferee',
            'enrollment_category_derived_at' => null,
        ]);

        $audit = AuditLog::query()->where('action', AuditAction::STUDENT_PROFILE_UPDATED)->sole();
        $auditPayload = json_encode([$audit->before_values, $audit->after_values], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Corrected Student', $auditPayload);
        self::assertStringNotContainsString('corrected.student@grc.test', $auditPayload);
        self::assertStringNotContainsString('Corrected Complete Street', $auditPayload);
        self::assertStringNotContainsString('+63 917 123 4567', $auditPayload);
        self::assertStringNotContainsString('2005-03-02', $auditPayload);
        self::assertStringNotContainsString('Corrected after reviewing the submitted admission documents.', $auditPayload);
    }

    public function test_admission_correcting_a_name_normalizes_the_casing_regardless_of_input(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Editor', 'admission.casing.edit@grc.test');
        $student = $this->student('Original Student', 'original.casing@grc.test', '2026-08-01093');
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'first_name' => 'MARY-JANE',
            'last_name' => "o'brien",
            'suffix' => 'jr',
            'reason' => 'Corrected the spelling and casing of the legal name.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', "Mary-Jane O'Brien Jr")
            ->assertJsonPath('data.first_name', 'Mary-Jane')
            ->assertJsonPath('data.last_name', "O'Brien")
            ->assertJsonPath('data.suffix', 'Jr');

        $this->assertDatabaseHas('users', [
            'id' => $student->user_id,
            'first_name' => 'Mary-Jane',
            'last_name' => "O'Brien",
            'suffix' => 'Jr',
        ]);
    }

    public function test_admission_update_requires_a_reason_and_in_person_verification(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Editor', 'admission.validation@grc.test');
        $student = $this->student('Validation Student', 'validation.student@grc.test', '2026-08-01089');
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'first_name' => 'Changed',
            'last_name' => 'Verification',
            'identity_verified_in_person' => false,
        ])->assertUnprocessable()
            ->assertJsonStructure([
                'error' => ['errors' => ['reason', 'identity_verified_in_person']],
            ]);
    }

    public function test_changing_the_year_level_no_longer_overwrites_the_type_admission_chose(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Keeper', 'admission.keeper@grc.test');
        $student = $this->student('Keeper Student', 'keeper.student@grc.test', '2026-08-01099');
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'student_type' => 'existing_student',
            'reason' => 'Existing student given an account.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.enrollment_category', 'regular')
            ->assertJsonPath('data.student_type', 'existing_student')
            ->assertJsonPath('data.student_type_label', 'Existing Student');

        // A later year-level correction leaves the type and the category alone (it used to recompute them).
        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'year_level' => 3,
            'reason' => 'Year level corrected.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.year_level', 3)
            ->assertJsonPath('data.enrollment_category', 'regular')
            ->assertJsonPath('data.student_type', 'existing_student');
    }

    public function test_admission_can_correct_personal_fields_but_not_academic_setup_after_the_first_enrollment(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Editor', 'admission.enrolled@grc.test');
        $student = $this->student('Enrolled Student', 'enrolled.student@grc.test', '2026-08-01092');
        $term = AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled,
            'enrolled_at' => now(),
        ]);
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'first_name' => 'Corrected',
            'last_name' => 'Enrolled Student',
            'street_barangay' => 'Updated Personal Address',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'reason' => 'The student presented updated identity and address records.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Corrected Enrolled Student')
            ->assertJsonPath('data.address', 'Updated Personal Address, Caloocan City, Metro Manila')
            ->assertJsonPath('data.academic_setup_editable', false);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'student_number' => '2026-08-01999',
            'reason' => 'Requested academic-record correction.',
            'identity_verified_in_person' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseHas('student_profiles', [
            'id' => $student->id,
            'student_number' => '2026-08-01092',
        ]);
    }

    public function test_the_category_and_the_single_address_text_cannot_be_set_by_a_correction(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Refuser', 'admission.refuser@grc.test');
        $student = $this->student('Refused Student', 'refused.student@grc.test', '2026-08-01110');
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'enrollment_category' => 'irregular',
            'address' => 'A single address text a client made up',
            'reason' => 'Trying to set what the system decides.',
            'identity_verified_in_person' => true,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['enrollment_category', 'address']]]);

        $this->assertDatabaseHas('student_profiles', [
            'id' => $student->id,
            'enrollment_category' => 'regular',
            'address' => 'Original Address, Caloocan City',
        ]);
    }

    public function test_one_address_part_rewrites_the_single_address_text_from_the_stored_parts(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Address', 'admission.address@grc.test');
        $student = $this->student('Address Student', 'address.student@grc.test', '2026-08-01111');
        $student->forceFill([
            'street_barangay' => '12 Sampaguita Street',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'address' => '12 Sampaguita Street, Caloocan City, Metro Manila',
        ])->save();
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'town_city' => 'Valenzuela City',
            'reason' => 'The student moved to another city.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.street_barangay', '12 Sampaguita Street')
            ->assertJsonPath('data.town_city', 'Valenzuela City')
            ->assertJsonPath('data.province', 'Metro Manila')
            ->assertJsonPath('data.address', '12 Sampaguita Street, Valenzuela City, Metro Manila');
    }

    public function test_a_partly_typed_address_on_an_older_account_keeps_its_free_text_until_all_three_parts_are_known(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Partial', 'admission.partial@grc.test');
        $student = $this->student('Older Student', 'older.student@grc.test', '2026-08-01114');
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'street_barangay' => '5 Mabini Street',
            'reason' => 'Started typing the address parts.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.street_barangay', '5 Mabini Street')
            ->assertJsonPath('data.address', 'Original Address, Caloocan City');

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'town_city' => 'Malabon City',
            'province' => 'Metro Manila',
            'reason' => 'Finished the address parts.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.address', '5 Mabini Street, Malabon City, Metro Manila');
    }

    public function test_a_detail_admission_types_is_no_longer_listed_as_a_generated_value(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Real Values', 'admission.real@grc.test');
        $student = $this->student('Generated Student', 'generated.student@grc.test', '2026-08-01112');
        $student->forceFill([
            'gender' => 'F',
            'birth_date' => '2004-01-01',
            'citizenship' => 'Filipino',
            'contact_number' => '09000000000',
            'demographics_demo_fields' => ['gender', 'birth_date', 'contact_number'],
        ])->save();
        Sanctum::actingAs($admission);

        $this->getJson('/api/v1/student-profiles/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.generated_fields', ['gender', 'birth_date', 'contact_number']);

        // The correction form sends every detail it shows: a value sent unchanged was not typed by anyone,
        // so it stays generated.
        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'gender' => 'F',
            'birth_date' => '2004-01-01',
            'contact_number' => '09000000000',
            'citizenship' => 'Filipino',
            'reason' => 'Saved with nothing changed.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.generated_fields', ['gender', 'birth_date', 'contact_number']);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'gender' => 'F',
            'birth_date' => '2004-09-30',
            'contact_number' => '09171234567',
            'reason' => 'The student gave the real details.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.generated_fields', ['gender']);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'gender' => 'M',
            'reason' => 'The student gave the real gender.',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.generated_fields', []);

        $this->assertDatabaseHas('student_profiles', ['id' => $student->id, 'demographics_demo_fields' => null]);
    }

    public function test_a_correction_refuses_a_future_birth_date_and_an_invalid_contact_number_or_gender(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Validator', 'admission.details@grc.test');
        $student = $this->student('Details Student', 'details.student@grc.test', '2026-08-01113');
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profiles/'.$student->id, [
            'gender' => 'X',
            'birth_date' => now()->addDay()->format('Y-m-d'),
            'contact_number' => 'call me',
            'reason' => 'Trying wrong values.',
            'identity_verified_in_person' => true,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['gender', 'birth_date', 'contact_number']]]);
    }

    public function test_admission_can_resend_setup_for_a_pending_account_but_not_an_active_account(): void
    {
        $admission = $this->user(UserRole::AdmissionStaff, 'Admission Inviter', 'admission.inviter@grc.test');
        $pending = $this->student('Pending Invite', 'pending.invite@grc.test', '2026-08-01090');
        $pending->user->forceFill([
            'status' => UserStatus::Disabled,
            'account_setup_completed_at' => null,
        ])->save();
        $active = $this->student('Active Student', 'active.student@grc.test', '2026-08-01091');
        $active->user->forceFill(['account_setup_completed_at' => now()])->save();
        Mail::fake();
        $oldCode = app(AccountSetupCodes::class)->issue($pending->user);
        Sanctum::actingAs($admission);

        $this->postJson('/api/v1/student-profiles/'.$pending->id.'/account-setup-invitations')
            ->assertOk()
            ->assertJsonPath('data.account_setup_status', 'pending')
            ->assertJsonPath('data.invitation_delivery_status', 'sent')
            ->assertJsonMissingPath('data.setup_code');

        Mail::assertSentCount(1);
        self::assertFalse(app(AccountSetupCodes::class)->attempt($pending->user->fresh(), $oldCode));

        $this->postJson('/api/v1/student-profiles/'.$active->id.'/account-setup-invitations')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
}
