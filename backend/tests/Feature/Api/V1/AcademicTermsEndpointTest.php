<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AcademicTermsEndpointTest extends TestCase
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

    private function seedTerms(): void
    {
        AcademicTerm::create(['school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterClosed]);
        AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/academic-terms')->assertUnauthorized();
    }

    public function test_a_faculty_member_does_not_see_the_planning_term_with_the_exact_envelope(): void
    {
        $this->seedTerms();
        $token = $this->tokenFor(UserRole::Faculty, 'faculty.terms@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/academic-terms');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $term = AcademicTerm::where('semester', '2nd')->sole();
        $response->assertExactJson([
            'data' => [
                [
                    'type' => 'academic-term',
                    'id' => $term->id,
                    'school_year' => '2025-2026',
                    'semester' => '2nd',
                    'starts_at' => null,
                    'ends_at' => null,
                    'enrollment_opens_at' => null,
                    'enrollment_closes_at' => null,
                    'add_drop_opens_at' => null,
                    'add_drop_deadline_at' => null,
                    'enrollment_platform' => null,
                    'enrollment_platform_label' => null,
                    'grading_deadline_at' => null,
                    'closed_at' => null,
                    'archived_at' => null,
                    'status' => 'semester_closed',
                    'status_label' => 'Semester Closed',
                    'is_actionable_current' => false,
                    'next_term_sequence' => [
                        'school_year' => '2026-2027',
                        'semester' => '1st',
                    ],
                ],
            ],
        ]);
    }

    public function test_a_registrar_head_receives_every_term_including_planning(): void
    {
        $this->seedTerms();
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.terms@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/academic-terms');

        $response->assertOk();
        self::assertCount(2, $response->json('data'));
    }

    public function test_results_are_ordered_by_school_year_descending_then_semester(): void
    {
        AcademicTerm::create(['school_year' => '2024-2025', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterClosed]);
        AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing]);
        AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $token = $this->tokenFor(UserRole::Dean, 'dean.terms@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/academic-terms');

        $ordering = collect($response->json('data'))
            ->map(fn (array $term): string => $term['school_year'].'-'.$term['semester'])
            ->all();

        self::assertSame(
            ['2026-2027-1st', '2026-2027-2nd', '2024-2025-1st'],
            $ordering,
        );
    }

    public function test_anonymous_request_to_create_is_unauthenticated(): void
    {
        $this->postJson('/api/v1/academic-terms', $this->validPayload())->assertUnauthorized();
    }

    public function test_a_registrar_head_can_create_a_term(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.create@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/academic-terms', $this->validPayload());

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.status_label', 'Draft');
        $response->assertJsonPath('data.school_year', '2028-2029');

        $term = AcademicTerm::where('school_year', '2028-2029')->sole();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'academic_term.created',
            'auditable_type' => 'academic_term',
            'auditable_id' => $term->id,
        ]);
    }

    public function test_a_second_non_archived_term_cannot_be_created_until_the_current_term_is_archived(): void
    {
        $current = AcademicTerm::create([
            'school_year' => '2027-2028',
            'semester' => '2nd',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        DB::table('academic_term_current_slots')->where('id', 1)->update([
            'academic_term_id' => $current->id,
        ]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.current-term@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/academic-terms', $this->validPayload());

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('school_year', $response->json('error.errors'));
    }

    /**
     * @dataProvider nonRegistrarHeadRoleProvider
     */
    public function test_a_non_registrar_head_role_cannot_create_a_term(UserRole $role): void
    {
        $token = $this->tokenFor($role, $role->value.'.create-term@grc.test');

        $this->withToken($token)->postJson('/api/v1/academic-terms', $this->validPayload())->assertForbidden();
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function nonRegistrarHeadRoleProvider(): array
    {
        $roles = [];

        foreach (UserRole::cases() as $role) {
            if ($role === UserRole::RegistrarHead) {
                continue;
            }

            $roles[$role->value] = [$role];
        }

        return $roles;
    }

    public function test_creating_a_duplicate_school_year_and_semester_is_rejected(): void
    {
        AcademicTerm::create(['school_year' => '2028-2029', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.duplicate@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/academic-terms', $this->validPayload());

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('school_year', $response->json('error.errors'));
    }

    public function test_enrollment_closes_before_it_opens_is_rejected(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.enroll.window@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/academic-terms', $this->validPayload([
            'enrollment_closes_at' => '2028-01-01T00:00:00Z',
        ]));

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('enrollment_closes_at', $response->json('error.errors'));
    }

    public function test_add_drop_deadline_before_enrollment_opens_is_rejected(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.add.drop@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/academic-terms', $this->validPayload([
            'add_drop_deadline_at' => '2028-01-01T00:00:00Z',
        ]));

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('add_drop_deadline_at', $response->json('error.errors'));
    }

    public function test_a_registrar_head_can_correct_a_draft_term_identity_without_changing_its_enrollment_schedule(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2028',
            'semester' => '2nd',
            'status' => AcademicTermStatus::Draft,
            'enrollment_opens_at' => '2026-07-01 08:00:00',
            'enrollment_closes_at' => '2026-07-15 17:00:00',
            'add_drop_deadline_at' => '2026-07-20 17:00:00',
        ]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.correct-draft@grc.test');

        $response = $this->withToken($token)->patchJson(
            "/api/v1/academic-terms/{$term->id}/draft-identity",
            ['school_year' => '2026-2027', 'semester' => '1st'],
        );

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.school_year', '2026-2027')
            ->assertJsonPath('data.semester', '1st')
            ->assertJsonPath('data.status', 'draft');

        $term->refresh();
        self::assertSame('2026-2027', $term->school_year);
        self::assertSame('1st', $term->semester);
        self::assertSame('2026-07-01T08:00:00Z', $term->enrollment_opens_at?->utc()->format('Y-m-d\\TH:i:s\\Z'));
        self::assertSame('2026-07-15T17:00:00Z', $term->enrollment_closes_at?->utc()->format('Y-m-d\\TH:i:s\\Z'));
        self::assertSame('2026-07-20T17:00:00Z', $term->add_drop_deadline_at?->utc()->format('Y-m-d\\TH:i:s\\Z'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'academic_term.draft_identity_updated',
            'auditable_type' => 'academic_term',
            'auditable_id' => $term->id,
        ]);
    }

    public function test_a_draft_term_correction_cannot_duplicate_an_existing_school_year_and_semester(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2028',
            'semester' => '2nd',
            'status' => AcademicTermStatus::Draft,
        ]);
        AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::Archived,
        ]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.correct-duplicate@grc.test');

        $response = $this->withToken($token)->patchJson(
            "/api/v1/academic-terms/{$term->id}/draft-identity",
            ['school_year' => '2026-2027', 'semester' => '1st'],
        );

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('school_year', $response->json('error.errors'));
        $this->assertDatabaseHas('academic_terms', [
            'id' => $term->id,
            'school_year' => '2026-2028',
            'semester' => '2nd',
        ]);
    }

    public function test_a_non_draft_term_cannot_have_its_school_year_or_semester_corrected(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2028',
            'semester' => '2nd',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.correct-ongoing@grc.test');

        $response = $this->withToken($token)->patchJson(
            "/api/v1/academic-terms/{$term->id}/draft-identity",
            ['school_year' => '2026-2027', 'semester' => '1st'],
        );

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('school_year', $response->json('error.errors'));
        $this->assertDatabaseHas('academic_terms', [
            'id' => $term->id,
            'school_year' => '2026-2028',
            'semester' => '2nd',
        ]);
    }

    public function test_a_non_registrar_head_cannot_correct_a_draft_term_identity(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2028',
            'semester' => '2nd',
            'status' => AcademicTermStatus::Draft,
        ]);
        $token = $this->tokenFor(UserRole::Dean, 'dean.correct-draft@grc.test');

        $this->withToken($token)->patchJson(
            "/api/v1/academic-terms/{$term->id}/draft-identity",
            ['school_year' => '2026-2027', 'semester' => '1st'],
        )->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'school_year' => '2028-2029',
            'semester' => '1st',
            'enrollment_opens_at' => '2028-07-01T00:00:00Z',
            'enrollment_closes_at' => '2028-07-15T00:00:00Z',
            'add_drop_deadline_at' => '2028-07-20T00:00:00Z',
        ], $overrides);
    }
}
