<?php

namespace Tests\Feature\Database;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\GrcSubjectCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the Phase 5 Program Chair curriculum/prerequisite workspace
 * (`POST /api/v1/curricula`) operates on the real CCS catalog, not only on
 * hand-built test fixtures or the synthetic `CS101`-style placeholders —
 * the concrete "set up the prerequisites of the subjects" capability
 * requested for Phase 6.
 */
final class GrcSubjectCatalogSeederCurriculumIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_a_program_chair_can_build_a_curriculum_from_the_real_ccs_catalog(): void
    {
        $this->seed(GrcSubjectCatalogSeeder::class);

        $program = Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active, 'college' => 'ccs']);
        // A curriculum is created against the current or latest academic term.
        AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing, 'starts_at' => '2026-08-01']);

        $intro = Subject::where('code', 'ITC')->sole();
        $hardware = Subject::where('code', 'ITP1')->sole();

        User::create([
            'name' => 'Chair', 'email' => 'chair.ccs@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'college' => 'ccs', 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'chair.ccs@grc.test', 'password' => self::PASSWORD,
        ])->json('data.token');

        $response = $this->withToken($token)->postJson('/api/v1/curricula', [
            'program_id' => $program->id,
            'name' => 'BSIT CCS Curriculum',
            'effective_school_year' => '2026-2027',
            'subjects' => [
                ['subject_id' => $intro->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true],
                [
                    'subject_id' => $hardware->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true,
                    'prerequisites' => [
                        ['prerequisite_subject_id' => $intro->id, 'minimum_grade' => '3.00'],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.subjects.0.code', 'ITC');
        $response->assertJsonPath('data.subjects.1.code', 'ITP1');
        $response->assertJsonCount(1, 'data.subjects.1.prerequisites');

        $this->assertDatabaseHas('curriculum_subjects', ['subject_id' => $intro->id]);
        $this->assertDatabaseHas('subject_prerequisites', [
            'prerequisite_subject_id' => $intro->id,
        ]);
    }
}
