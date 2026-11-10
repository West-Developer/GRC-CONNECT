<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\FinancialStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Advance Payment page used to reuse the payment-queue candidate finder,
 * which only returns students with a `pending_payment` enrollment and an open
 * ticket, so a student who had already enrolled (or never started) could not be
 * found and advance payment became unusable. This lookup depends on none of that.
 */
final class CashierStudentLookupTest extends TestCase
{
    use RefreshDatabase;

    private Curriculum $curriculum;

    private AcademicTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active]);
        $this->curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT', 'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Active,
        ]);
    }

    private function user(UserRole $role, string $prefix): User
    {
        return User::create([
            'name' => 'Test '.$prefix, 'email' => $prefix.'.lookup@grc.test', 'password' => 'secret-password-1',
            'role' => $role, 'status' => UserStatus::Active,
        ]);
    }

    private function student(string $number, string $name, ?FinancialStatus $status = null): StudentProfile
    {
        $user = User::create([
            'name' => $name, 'email' => strtolower($number).'@grc.test', 'password' => 'secret-password-1',
            'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $number, 'program_id' => $this->curriculum->program_id,
            'curriculum_id' => $this->curriculum->id, 'year_level' => 2,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
            'financial_status' => $status,
        ]);
    }

    private function enrollment(StudentProfile $student, EnrollmentStatus $status): void
    {
        Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $this->term->id, 'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    private function actingAsAccounting(): void
    {
        Sanctum::actingAs($this->user(UserRole::AccountingStaff, 'cashier'));
    }

    public function test_it_finds_a_student_who_has_already_enrolled_and_one_who_never_started(): void
    {
        $enrolled = $this->student('2026-0001', 'Kirk Perez');
        $this->enrollment($enrolled, EnrollmentStatus::Enrolled);
        $fresh = $this->student('2026-0002', 'Ana Reyes');
        $this->actingAsAccounting();

        $this->getJson('/api/v1/cashier-student-lookup?search=2026-0001')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $enrolled->id)
            ->assertJsonPath('data.0.student_number', '2026-0001')
            ->assertJsonPath('data.0.student_name', 'Kirk Perez');

        $this->getJson('/api/v1/cashier-student-lookup?search=2026-0002')->assertOk()
            ->assertJsonPath('data.0.student_id', $fresh->id);
    }

    public function test_it_can_be_used_again_after_a_first_search(): void
    {
        $this->student('2026-0001', 'Kirk Perez');
        $this->student('2026-0002', 'Ana Reyes');
        $this->actingAsAccounting();

        $this->getJson('/api/v1/cashier-student-lookup?search=Kirk')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/cashier-student-lookup?search=Ana')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/cashier-student-lookup?search=Kirk')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_it_matches_a_name_fragment_or_an_exact_email_and_lists_several_in_name_order(): void
    {
        $this->student('2026-0010', 'Maria Santos');
        $this->student('2026-0011', 'Marian Cruz');
        $this->student('2026-0012', 'Jose Rizal');
        $this->actingAsAccounting();

        $names = fn (string $query): array => collect(
            $this->getJson('/api/v1/cashier-student-lookup?search='.urlencode($query))->assertOk()->json('data'),
        )->pluck('student_name')->all();

        self::assertSame(['Maria Santos', 'Marian Cruz'], $names('Mari'));
        self::assertSame(['Jose Rizal'], $names('2026-0012@grc.test'));
        self::assertSame([], $names('Nobody Here'));
    }

    public function test_results_are_capped_at_ten(): void
    {
        foreach (range(1, 12) as $i) {
            $this->student(sprintf('2026-1%03d', $i), 'Student Number '.$i);
        }
        $this->actingAsAccounting();

        $this->getJson('/api/v1/cashier-student-lookup?search=Student')->assertOk()->assertJsonCount(10, 'data');
    }

    public function test_a_row_carries_only_the_fields_the_cashier_needs(): void
    {
        $this->student('2026-0020', 'Lea Gomez', FinancialStatus::Scholar);
        $this->student('2026-0021', 'Tomas Diaz');
        $this->actingAsAccounting();

        $scholar = $this->getJson('/api/v1/cashier-student-lookup?search=2026-0020')->assertOk()->json('data.0');
        self::assertSame(
            ['type', 'student_id', 'student_number', 'student_name', 'year_level', 'financial_status', 'financial_status_label'],
            array_keys($scholar),
        );
        self::assertSame('scholar', $scholar['financial_status']);
        self::assertSame('Scholar', $scholar['financial_status_label']);

        $this->getJson('/api/v1/cashier-student-lookup?search=2026-0021')->assertOk()
            ->assertJsonPath('data.0.financial_status', 'payee');
    }

    public function test_a_search_needs_at_least_two_characters(): void
    {
        $this->actingAsAccounting();

        $this->getJson('/api/v1/cashier-student-lookup')->assertUnprocessable();
        $this->getJson('/api/v1/cashier-student-lookup?search=a')->assertUnprocessable();
    }

    public function test_only_accounting_staff_may_search(): void
    {
        $this->student('2026-0030', 'Some Student');

        $this->getJson('/api/v1/cashier-student-lookup?search=Some')->assertUnauthorized();

        foreach ([UserRole::Student, UserRole::RegistrarHead, UserRole::RegistrarStaff, UserRole::Dean, UserRole::AdmissionStaff] as $role) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($this->user($role, $role->value));
            $this->getJson('/api/v1/cashier-student-lookup?search=Some')->assertForbidden();
        }
    }
}
