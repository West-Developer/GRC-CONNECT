<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admission types a student's full middle name (2026-10-16). Everywhere a name is displayed it is still the
 * initial ("West R. Ragma"); only Admission, and the student on their own profile, ever see the full name.
 */
final class StudentMiddleNameTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_admission_types_the_full_middle_name_and_the_display_name_shows_only_the_initial(): void
    {
        Mail::fake();
        $this->actingAsStaff(UserRole::AdmissionStaff);

        $response = $this->postJson('/api/v1/student-profiles', $this->payload('west@grc.test', 'west', 'ramga', 'RAGMA'))
            ->assertCreated()
            ->assertJsonPath('data.name', 'West R. Ragma')
            ->assertJsonPath('data.first_name', 'West')
            ->assertJsonPath('data.middle_initial', 'R')
            ->assertJsonPath('data.middle_name', 'Ramga')
            ->assertJsonPath('data.last_name', 'Ragma');

        $this->assertDatabaseHas('users', [
            'email' => 'west@grc.test',
            'name' => 'West R. Ragma',
            'middle_name' => 'Ramga',
            'middle_initial' => 'R',
        ]);
        self::assertIsInt($response->json('data.id'));
    }

    public function test_a_middle_name_of_several_words_is_shown_as_its_first_letter(): void
    {
        Mail::fake();
        $this->actingAsStaff(UserRole::AdmissionStaff);

        $this->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'juan', 'dela cruz', 'santos'))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Juan D. Santos')
            ->assertJsonPath('data.middle_name', 'Dela Cruz')
            ->assertJsonPath('data.middle_initial', 'D');
    }

    public function test_the_middle_name_is_optional_and_not_too_long(): void
    {
        Mail::fake();
        $this->actingAsStaff(UserRole::AdmissionStaff);

        $this->postJson('/api/v1/student-profiles', $this->payload('nomiddle@grc.test', 'Ana', null, 'Reyes'))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ana Reyes')
            ->assertJsonPath('data.middle_name', null)
            ->assertJsonPath('data.middle_initial', null);

        $this->postJson('/api/v1/student-profiles', $this->payload('toolong@grc.test', 'Ana', str_repeat('a', 101), 'Reyes'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_only_admission_and_the_student_themself_receive_the_full_middle_name(): void
    {
        [$studentUser, $profile] = $this->student('ramga.student@grc.test', 'Ramga', 'R');

        $this->actingAsStaff(UserRole::AdmissionStaff);
        $this->getJson('/api/v1/student-profiles/'.$profile->id)
            ->assertOk()
            ->assertJsonPath('data.middle_name', 'Ramga')
            ->assertJsonPath('data.middle_initial', 'R');
        $this->getJson('/api/v1/student-profiles')
            ->assertOk()
            ->assertJsonPath('data.0.middle_name', 'Ramga');

        Sanctum::actingAs($studentUser);
        $this->getJson('/api/v1/student-profile')
            ->assertOk()
            ->assertJsonPath('data.name', 'West R. Ragma')
            ->assertJsonPath('data.middle_name', 'Ramga');

        // The Registrar Head reads the same profile but only ever gets the initial.
        $this->actingAsStaff(UserRole::RegistrarHead);
        $this->getJson('/api/v1/students/'.$profile->id.'/registrar-profile')
            ->assertOk()
            ->assertJsonPath('data.name', 'West R. Ragma')
            ->assertJsonPath('data.middle_initial', 'R')
            ->assertJsonPath('data.middle_name', null);
    }

    public function test_changing_the_middle_name_changes_the_initial_in_the_display_name(): void
    {
        [, $profile] = $this->student('change.middle@grc.test', 'Ramga', 'R');
        $this->actingAsStaff(UserRole::AdmissionStaff);

        $this->patchJson('/api/v1/student-profiles/'.$profile->id, $this->edit(['middle_name' => 'santos']))
            ->assertOk()
            ->assertJsonPath('data.name', 'West S. Ragma')
            ->assertJsonPath('data.middle_name', 'Santos')
            ->assertJsonPath('data.middle_initial', 'S');
        $this->assertDatabaseHas('users', ['id' => $profile->user_id, 'name' => 'West S. Ragma', 'middle_name' => 'Santos', 'middle_initial' => 'S']);

        $this->patchJson('/api/v1/student-profiles/'.$profile->id, $this->edit(['middle_name' => null]))
            ->assertOk()
            ->assertJsonPath('data.name', 'West Ragma')
            ->assertJsonPath('data.middle_name', null)
            ->assertJsonPath('data.middle_initial', null);
    }

    public function test_an_older_account_with_only_an_initial_keeps_it_when_the_form_sends_it_back(): void
    {
        [, $profile] = $this->student('older.initial@grc.test', null, 'S');
        $this->actingAsStaff(UserRole::AdmissionStaff);

        // The edit form shows the initial in the Middle name box; saving something else sends it back as it was.
        $this->patchJson('/api/v1/student-profiles/'.$profile->id, $this->edit(['middle_name' => 'S', 'first_name' => 'Westley']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Westley S. Ragma')
            ->assertJsonPath('data.middle_name', null)
            ->assertJsonPath('data.middle_initial', 'S');

        $this->patchJson('/api/v1/student-profiles/'.$profile->id, $this->edit(['middle_name' => 'Santos']))
            ->assertOk()
            ->assertJsonPath('data.middle_name', 'Santos')
            ->assertJsonPath('data.middle_initial', 'S');
    }

    public function test_a_student_requests_a_middle_name_change_and_admission_approves_it(): void
    {
        [$studentUser, $profile] = $this->student('request.middle@grc.test', null, null);
        Sanctum::actingAs($studentUser);

        $requestId = $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => 'West',
            'middle_name' => 'reyes',
            'last_name' => 'Ragma',
            'email' => 'request.middle@grc.test',
            'address' => 'Official Address, Caloocan City',
            'reason' => 'I do not have my middle name on record.',
        ])->assertCreated()
            ->assertJsonPath('data.requested.name', 'West R. Ragma')
            ->assertJsonPath('data.requested.middle_name', 'Reyes')
            ->assertJsonPath('data.requested.middle_initial', 'R')
            ->assertJsonPath('data.official.middle_name', null)
            ->json('data.id');

        $this->actingAsStaff(UserRole::AdmissionStaff);
        $this->patchJson('/api/v1/student-profile-change-requests/'.$requestId.'/decision', [
            'action' => 'approve',
            'identity_verified_in_person' => true,
        ])->assertOk()
            ->assertJsonPath('data.official.name', 'West R. Ragma')
            ->assertJsonPath('data.official.middle_name', 'Reyes');

        $this->assertDatabaseHas('users', [
            'id' => $studentUser->id,
            'name' => 'West R. Ragma',
            'middle_name' => 'Reyes',
            'middle_initial' => 'R',
        ]);
        self::assertSame('Reyes', StudentProfile::query()->findOrFail($profile->id)->user->middle_name);

        // The same middle name again (however it is typed) changes nothing, so it is not a request.
        Sanctum::actingAs($studentUser);
        $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => 'West',
            'middle_name' => 'REYES',
            'last_name' => 'Ragma',
            'email' => 'request.middle@grc.test',
            'address' => 'Official Address, Caloocan City',
            'reason' => 'Nothing actually changes.',
        ])->assertUnprocessable();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $email, string $first, ?string $middle, string $last): array
    {
        $program = Program::query()->first() ?? Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active]);
        Curriculum::query()->first() ?? Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum',
            'effective_school_year' => '2026-2027', 'effective_start_year' => 2026,
            'effective_end_year' => 2030, 'status' => CurriculumStatus::Active,
        ]);
        AcademicTerm::query()->first() ?? AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);

        return [
            'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'email' => $email,
            'gender' => 'F', 'birth_date' => '2006-05-04', 'citizenship' => 'Filipino',
            'contact_number' => '09171234567', 'street_barangay' => '123 Test Street, Caloocan City',
            'town_city' => 'Caloocan City', 'province' => 'Metro Manila',
            'program_id' => $program->id, 'year_level' => 1,
            'requirements_verified' => true, 'student_type' => StudentType::Freshman->value,
            'privacy_notice_acknowledged' => true, 'privacy_notice_version' => config('privacy.notice_version'),
        ];
    }

    /** @return array{User, StudentProfile} */
    private function student(string $email, ?string $middleName, ?string $middleInitial): array
    {
        $program = Program::query()->first() ?? Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::query()->first() ?? Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum',
            'effective_school_year' => '2026-2027', 'effective_start_year' => 2026,
            'effective_end_year' => 2030, 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => $middleInitial !== null ? 'West '.$middleInitial.'. Ragma' : 'West Ragma',
            'first_name' => 'West', 'middle_name' => $middleName, 'middle_initial' => $middleInitial, 'last_name' => 'Ragma',
            'email' => $email, 'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);
        $profile = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-10-'.str_pad((string) (3000 + $user->id), 5, '0', STR_PAD_LEFT),
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'entry_year' => 2026, 'year_level' => 1,
            'student_type' => StudentType::ExistingStudent, 'enrollment_category' => 'regular',
            'admission_status' => 'admitted', 'academic_standing' => 'good',
            'address' => 'Official Address, Caloocan City',
        ]);

        return [$user, $profile];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function edit(array $changes): array
    {
        return [...$changes, 'reason' => 'Corrected at Admission.', 'identity_verified_in_person' => true];
    }

    private function actingAsStaff(UserRole $role): User
    {
        $user = User::query()->where('email', $role->value.'@grc.test')->first() ?? User::create([
            'name' => 'Test '.$role->value, 'email' => $role->value.'@grc.test', 'password' => self::PASSWORD,
            'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        return $user;
    }
}
