<?php

namespace Tests\Feature\Actions\Organization;

use App\Domain\Enrollment\EnrollmentAudience;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\AcademicTermEnrollmentWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers `POST /academic-terms/{academicTerm}/archive-and-create-next` —
 * the Registrar's single action to close one cycle and open the next.
 */
final class ArchiveAndCreateNextTermTest extends TestCase
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

    public function test_archiving_the_current_term_opens_the_next_one_as_draft(): void
    {
        $current = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        DB::table('academic_term_current_slots')->where('id', 1)->update(['academic_term_id' => $current->id]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.archive-next@grc.test');

        $response = $this->withToken($token)->postJson(
            "/api/v1/academic-terms/{$current->id}/archive-and-create-next",
            ['school_year' => '2027-2028', 'semester' => '1st'],
        );

        $response->assertCreated();
        $response->assertJsonPath('data.school_year', '2027-2028');
        $response->assertJsonPath('data.semester', '1st');
        $response->assertJsonPath('data.status', 'draft');
        // The new term carries no dates — the Registrar sets them
        // afterwards on the enrollment schedule card.
        $response->assertJsonPath('data.enrollment_opens_at', null);
        $response->assertJsonPath('data.enrollment_closes_at', null);

        $current->refresh();
        self::assertSame(AcademicTermStatus::Archived, $current->status);
        self::assertNotNull($current->closed_at);
        self::assertNotNull($current->archived_at);

        $createdId = (int) $response->json('data.id');
        self::assertSame(4, AcademicTermCollegeWorkflow::where('academic_term_id', $createdId)->count());
        foreach (AcademicTermCollegeWorkflow::where('academic_term_id', $createdId)->get() as $workflow) {
            self::assertSame(AcademicTermCollegeWorkflowStage::Draft, $workflow->stage);
        }
        self::assertSame(
            count(EnrollmentAudience::cases()),
            AcademicTermEnrollmentWindow::where('academic_term_id', $createdId)->count(),
        );

        $slot = DB::table('academic_term_current_slots')->where('id', 1)->first();
        self::assertSame($createdId, $slot->academic_term_id);
    }

    public function test_a_closed_term_can_also_be_archived_and_replaced(): void
    {
        $current = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterClosed,
            'closed_at' => now()->subDay(),
        ]);
        DB::table('academic_term_current_slots')->where('id', 1)->update(['academic_term_id' => $current->id]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.archive-closed@grc.test');

        $this->withToken($token)->postJson(
            "/api/v1/academic-terms/{$current->id}/archive-and-create-next",
            ['school_year' => '2027-2028', 'semester' => '1st'],
        )->assertCreated();

        self::assertSame(AcademicTermStatus::Archived, $current->refresh()->status);
    }

    public function test_a_duplicate_school_year_and_semester_is_rejected(): void
    {
        $current = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        AcademicTerm::create([
            'school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::Archived,
        ]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.archive-duplicate@grc.test');

        $response = $this->withToken($token)->postJson(
            "/api/v1/academic-terms/{$current->id}/archive-and-create-next",
            ['school_year' => '2027-2028', 'semester' => '1st'],
        );

        $response->assertUnprocessable();
        self::assertSame(AcademicTermStatus::SemesterOngoing, $current->refresh()->status);
    }

    public function test_a_non_registrar_head_role_cannot_archive_and_create_next(): void
    {
        $current = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.archive-next@grc.test');

        $this->withToken($token)->postJson(
            "/api/v1/academic-terms/{$current->id}/archive-and-create-next",
            ['school_year' => '2027-2028', 'semester' => '1st'],
        )->assertForbidden();

        self::assertSame(AcademicTermStatus::SemesterOngoing, $current->refresh()->status);
    }

    public function test_an_illegal_source_status_is_rejected_and_creates_nothing(): void
    {
        $current = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::Draft,
        ]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.archive-draft@grc.test');
        $before = AcademicTerm::query()->count();

        $this->withToken($token)->postJson(
            "/api/v1/academic-terms/{$current->id}/archive-and-create-next",
            ['school_year' => '2027-2028', 'semester' => '1st'],
        )->assertUnprocessable();

        self::assertSame($before, AcademicTerm::query()->count());
        self::assertSame(AcademicTermStatus::Draft, $current->refresh()->status);
    }

    public function test_archiving_without_payload_automatically_determines_next_sequence_for_2nd_semester(): void
    {
        $current = AcademicTerm::create([
            'school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        DB::table('academic_term_current_slots')->where('id', 1)->update(['academic_term_id' => $current->id]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.archive-auto-2nd@grc.test');

        $response = $this->withToken($token)->postJson(
            "/api/v1/academic-terms/{$current->id}/archive-and-create-next",
            [],
        );

        $response->assertCreated();
        $response->assertJsonPath('data.school_year', '2026-2027');
        $response->assertJsonPath('data.semester', '1st');
        $response->assertJsonPath('data.status', 'draft');

        $current->refresh();
        self::assertSame(AcademicTermStatus::Archived, $current->status);
    }

    public function test_archiving_without_payload_automatically_determines_next_sequence_for_1st_semester(): void
    {
        $current = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        DB::table('academic_term_current_slots')->where('id', 1)->update(['academic_term_id' => $current->id]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.archive-auto-1st@grc.test');

        $response = $this->withToken($token)->postJson(
            "/api/v1/academic-terms/{$current->id}/archive-and-create-next",
            [],
        );

        $response->assertCreated();
        $response->assertJsonPath('data.school_year', '2026-2027');
        $response->assertJsonPath('data.semester', '2nd');
        $response->assertJsonPath('data.status', 'draft');

        $current->refresh();
        self::assertSame(AcademicTermStatus::Archived, $current->status);
    }

    public function test_compute_next_sequence_rules(): void
    {
        self::assertSame(
            ['school_year' => '2025-2026', 'semester' => '2nd'],
            AcademicTerm::computeNextSequence('2025-2026', '1st'),
        );
        self::assertSame(
            ['school_year' => '2026-2027', 'semester' => '1st'],
            AcademicTerm::computeNextSequence('2025-2026', '2nd'),
        );
        self::assertSame(
            ['school_year' => '2026-2027', 'semester' => '2nd'],
            AcademicTerm::computeNextSequence('2026-2027', '1st'),
        );
        self::assertSame(
            ['school_year' => '2027-2028', 'semester' => '1st'],
            AcademicTerm::computeNextSequence('2026-2027', '2nd'),
        );
    }
}
