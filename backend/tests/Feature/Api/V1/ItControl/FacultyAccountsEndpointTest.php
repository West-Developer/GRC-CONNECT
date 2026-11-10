<?php

namespace Tests\Feature\Api\V1\ItControl;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\FacultyEmploymentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\FacultyAvailability;
use App\Models\FacultySpecialization;
use App\Models\FacultySubjectPreference;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FacultyAccountsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_requests_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/it-control/faculty')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_it_lists_and_filters_faculty_accounts(): void
    {
        $itAdmin = $this->makeUser('it-admin', UserRole::ItAdmin);
        $matching = $this->makeUser('matching', UserRole::Faculty, 'Matched Faculty', 'matched.faculty@grc.test', CollegeCode::Ccs, FacultyEmploymentType::FullTime);
        $this->makeUser('wrong-college', UserRole::Faculty, 'Other Faculty', 'other.faculty@grc.test', CollegeCode::Coe, FacultyEmploymentType::FullTime);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Introduction to Computing', 'units' => 3, 'status' => SubjectStatus::Active]);
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);
        FacultyAvailability::create(['professor_id' => $matching->id, 'day_of_week' => 1, 'starts_at_time' => '08:00:00', 'ends_at_time' => '10:00:00']);
        FacultySubjectPreference::create(['professor_id' => $matching->id, 'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1]);
        FacultySpecialization::create(['professor_id' => $matching->id, 'subject_id' => $subject->id, 'proficiency' => 'primary', 'source' => 'manual']);

        $response = $this->withToken($this->tokenFor($itAdmin))
            ->getJson('/api/v1/it-control/faculty?college=ccs&employment_type=full_time&status=active&per_page=20');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('data.0.type', 'it-control-faculty-account')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.email', 'matched.faculty@grc.test')
            ->assertJsonPath('data.0.college', 'ccs')
            ->assertJsonPath('data.0.availability_window_count', 1)
            ->assertJsonPath('data.0.subject_preference_count', 1)
            ->assertJsonPath('data.0.specialization_count', 1)
            ->assertJsonPath('data.0.password_hint', 'password');
        self::assertSame([
            'type', 'id', 'name', 'first_name', 'last_name', 'suffix', 'email', 'college', 'employment_type', 'status',
            'is_archived', 'availability_window_count', 'subject_preference_count', 'specialization_count', 'password_hint',
        ], array_keys($response->json('data.0')));
    }

    public function test_it_searches_by_faculty_name_and_email(): void
    {
        $itAdmin = $this->makeUser('it-admin-search', UserRole::ItAdmin);
        $faculty = $this->makeUser('searchable', UserRole::Faculty, 'Avery Faculty', 'avery.faculty@grc.test', CollegeCode::Ccs, FacultyEmploymentType::PartTime);

        foreach (['Avery Faculty', 'avery.faculty@grc.test'] as $query) {
            $this->withToken($this->tokenFor($itAdmin))
                ->getJson('/api/v1/it-control/faculty?q='.urlencode($query))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $faculty->id);
        }
    }

    #[DataProvider('otherRoles')]
    public function test_every_other_role_is_forbidden(UserRole $role): void
    {
        $this->withToken($this->tokenFor($this->makeUser('forbidden-'.$role->value, $role)))
            ->getJson('/api/v1/it-control/faculty')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
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

    private function makeUser(
        string $handle,
        UserRole $role,
        ?string $name = null,
        ?string $email = null,
        ?CollegeCode $college = null,
        ?FacultyEmploymentType $employmentType = null,
    ): User {
        return User::create([
            'name' => $name ?? 'User '.$handle,
            'email' => $email ?? $handle.'@grc.test',
            'password' => 'password',
            'role' => $role,
            'college' => $college,
            'employment_type' => $employmentType,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('it-control-faculty-test')->plainTextToken;
    }
}
