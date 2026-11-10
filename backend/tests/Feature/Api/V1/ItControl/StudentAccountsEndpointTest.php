<?php

namespace Tests\Feature\Api\V1\ItControl;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StudentAccountsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_requests_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/it-control/students')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_it_lists_and_filters_student_accounts(): void
    {
        [$program, $curriculum] = $this->makeProgramAndCurriculum(CollegeCode::Ccs);
        $itAdmin = $this->makeUser('it-admin', UserRole::ItAdmin);
        $matching = $this->makeStudent('matching', $program, $curriculum, [
            'name' => 'Matched Student',
            'student_number' => '2026-08-30001',
            'year_level' => 3,
            'enrollment_category' => 'irregular',
        ]);
        $this->makeStudent('wrong-year', $program, $curriculum, [
            'year_level' => 2,
            'enrollment_category' => 'irregular',
        ]);

        $term = AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        Enrollment::create([
            'student_id' => $matching->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment,
            'total_units' => 18,
        ]);

        $response = $this->withToken($this->tokenFor($itAdmin))
            ->getJson('/api/v1/it-control/students?college=ccs&year_level=3&enrollment_category=irregular&per_page=20');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('data.0.type', 'it-control-student-account')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.student_number', '2026-08-30001')
            ->assertJsonPath('data.0.program_code', 'BSCS')
            ->assertJsonPath('data.0.college', 'ccs')
            ->assertJsonPath('data.0.current_term_enrollment_status', EnrollmentStatus::PendingPayment->value)
            ->assertJsonPath('data.0.password_hint', 'password');
        $this->assertNotEmpty($response->json('data.0.email'));
        self::assertSame([
            'type', 'id', 'user_id', 'student_number', 'name', 'first_name', 'last_name', 'suffix', 'email', 'program_code',
            'college', 'year_level', 'enrollment_category', 'academic_standing', 'status', 'is_archived',
            'current_term_enrollment_status', 'password_hint',
        ], array_keys($response->json('data.0')));
    }

    public function test_it_searches_by_student_number_name_and_email(): void
    {
        [$program, $curriculum] = $this->makeProgramAndCurriculum(CollegeCode::Ccs);
        $itAdmin = $this->makeUser('it-admin-search', UserRole::ItAdmin);
        $student = $this->makeStudent('searchable', $program, $curriculum, [
            'name' => 'Avery Student',
            'email' => 'avery.student@grc.test',
            'student_number' => '2026-08-30002',
        ]);

        foreach (['2026-08-30002', 'Avery Student', 'avery.student@grc.test'] as $query) {
            $this->withToken($this->tokenFor($itAdmin))
                ->getJson('/api/v1/it-control/students?q='.urlencode($query))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $student->id);
        }
    }

    #[DataProvider('otherRoles')]
    public function test_every_other_role_is_forbidden(UserRole $role): void
    {
        $this->withToken($this->tokenFor($this->makeUser('forbidden-'.$role->value, $role)))
            ->getJson('/api/v1/it-control/students')
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

    /** @return array{Program, Curriculum} */
    private function makeProgramAndCurriculum(CollegeCode $college): array
    {
        $program = Program::create([
            'code' => 'BSCS', 'name' => 'BS Computer Science', 'college' => $college, 'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);

        return [$program, $curriculum];
    }

    /** @param array{name?: string, email?: string, student_number?: string, year_level?: int, enrollment_category?: ?string} $overrides */
    private function makeStudent(string $handle, Program $program, Curriculum $curriculum, array $overrides = []): StudentProfile
    {
        $user = $this->makeUser(
            $handle,
            UserRole::Student,
            $overrides['name'] ?? 'Student '.$handle,
            $overrides['email'] ?? $handle.'@grc.test',
        );

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $overrides['student_number'] ?? '2026-08-'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => $overrides['year_level'] ?? 1,
            'enrollment_category' => $overrides['enrollment_category'] ?? 'regular',
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
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
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('it-control-student-test')->plainTextToken;
    }
}
