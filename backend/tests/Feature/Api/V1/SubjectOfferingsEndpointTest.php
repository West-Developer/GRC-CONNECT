<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubjectOfferingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/subject-offerings?academic_term_id=1&curriculum_id=1')->assertUnauthorized();
    }

    public function test_a_program_chair_can_replace_offerings_and_read_them_back(): void
    {
        [$term, $curriculum, $subjectOne, $subjectTwo] = $this->makeScaffold();
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.offerings@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/subject-offerings', [
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'offerings' => [
                [
                    'subject_id' => $subjectOne->id,
                    'year_level' => 1,
                    'semester' => '1st',
                    'min_section_capacity' => 20,
                    'max_section_capacity' => 40,
                    'recommended_sections' => 2,
                ],
                [
                    'subject_id' => $subjectTwo->id,
                    'year_level' => 1,
                    'semester' => '2nd',
                    'min_section_capacity' => 15,
                    'max_section_capacity' => 30,
                    'recommended_sections' => 1,
                ],
            ],
        ]);

        $response->assertOk();
        self::assertCount(2, $response->json('data'));
        $response->assertJsonPath('data.0.subject_code', $subjectOne->code);
        $response->assertJsonPath('data.0.max_section_capacity', 40);

        $listResponse = $this->withToken($token)->getJson(
            "/api/v1/subject-offerings?academic_term_id={$term->id}&curriculum_id={$curriculum->id}",
        );
        $listResponse->assertOk();
        self::assertCount(2, $listResponse->json('data'));
    }

    public function test_replacing_offerings_deletes_the_previous_set(): void
    {
        [$term, $curriculum, $subjectOne, $subjectTwo] = $this->makeScaffold();
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.replace@grc.test');

        $this->withToken($token)->postJson('/api/v1/subject-offerings', [
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'offerings' => [
                [
                    'subject_id' => $subjectOne->id, 'year_level' => 1, 'semester' => '1st',
                    'min_section_capacity' => 20, 'max_section_capacity' => 40, 'recommended_sections' => 2,
                ],
                [
                    'subject_id' => $subjectTwo->id, 'year_level' => 1, 'semester' => '2nd',
                    'min_section_capacity' => 15, 'max_section_capacity' => 30, 'recommended_sections' => 1,
                ],
            ],
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/subject-offerings', [
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'offerings' => [
                [
                    'subject_id' => $subjectOne->id, 'year_level' => 1, 'semester' => '1st',
                    'min_section_capacity' => 25, 'max_section_capacity' => 45, 'recommended_sections' => 3,
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('subject_offerings', 1);
        $this->assertDatabaseHas('subject_offerings', [
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subjectOne->id,
            'min_section_capacity' => 25,
        ]);
    }

    public function test_a_subject_not_placed_in_the_curriculum_is_rejected(): void
    {
        [$term, $curriculum, , , $unplacedSubject] = $this->makeScaffold();
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.unplaced@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/subject-offerings', [
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'offerings' => [
                [
                    'subject_id' => $unplacedSubject->id, 'year_level' => 1, 'semester' => '1st',
                    'min_section_capacity' => 10, 'max_section_capacity' => 20, 'recommended_sections' => 1,
                ],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('offerings.0.subject_id', $response->json('error.errors'));
    }

    public function test_max_capacity_below_minimum_is_rejected(): void
    {
        [$term, $curriculum, $subjectOne] = $this->makeScaffold();
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.minmax@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/subject-offerings', [
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'offerings' => [
                [
                    'subject_id' => $subjectOne->id, 'year_level' => 1, 'semester' => '1st',
                    'min_section_capacity' => 40, 'max_section_capacity' => 20, 'recommended_sections' => 1,
                ],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('offerings.0.max_section_capacity', $response->json('error.errors'));
    }

    /**
     * @dataProvider nonProgramChairRoleProvider
     */
    public function test_a_non_program_chair_role_cannot_replace_offerings(UserRole $role): void
    {
        [$term, $curriculum, $subjectOne] = $this->makeScaffold();
        $token = $this->tokenFor($role, $role->value.'.offerings-forbidden@grc.test');

        $this->withToken($token)->postJson('/api/v1/subject-offerings', [
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'offerings' => [
                [
                    'subject_id' => $subjectOne->id, 'year_level' => 1, 'semester' => '1st',
                    'min_section_capacity' => 10, 'max_section_capacity' => 20, 'recommended_sections' => 1,
                ],
            ],
        ])->assertForbidden();
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function nonProgramChairRoleProvider(): array
    {
        $roles = [];

        foreach (UserRole::cases() as $role) {
            if ($role === UserRole::ProgramChair) {
                continue;
            }

            $roles[$role->value] = [$role];
        }

        return $roles;
    }

    /**
     * @return array{0: AcademicTerm, 1: Curriculum, 2: Subject, 3: Subject, 4: Subject}
     */
    private function makeScaffold(): array
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::Draft,
        ]);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS 2026', 'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Active,
        ]);
        $subjectOne = Subject::create(['code' => 'SO201', 'title' => 'Offering Subject One', 'units' => 3, 'status' => SubjectStatus::Active]);
        $subjectTwo = Subject::create(['code' => 'SO202', 'title' => 'Offering Subject Two', 'units' => 3, 'status' => SubjectStatus::Active]);
        $unplacedSubject = Subject::create(['code' => 'SO203', 'title' => 'Unplaced Subject', 'units' => 3, 'status' => SubjectStatus::Active]);

        $curriculum->subjectPlacements()->create(['subject_id' => $subjectOne->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        $curriculum->subjectPlacements()->create(['subject_id' => $subjectTwo->id, 'year_level' => 1, 'semester' => '2nd', 'is_required' => true]);

        return [$term, $curriculum, $subjectOne, $subjectTwo, $unplacedSubject];
    }

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
}
