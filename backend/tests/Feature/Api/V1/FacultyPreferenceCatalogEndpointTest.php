<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacultyPreferenceCatalogEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenForFaculty(): string
    {
        $user = User::create([
            'name' => 'Faculty Catalog Test',
            'email' => 'faculty.catalog-fractional@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::Faculty,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
            'college' => CollegeCode::Ccs,
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_it_emits_a_fractional_unit_subject_verbatim(): void
    {
        $program = Program::create([
            'code' => 'BSIT',
            'name' => 'Information Technology',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => '2024–2029',
            'effective_school_year' => '2024-2029',
            'effective_start_year' => 2024,
            'effective_end_year' => 2029,
            'status' => CurriculumStatus::Active,
        ]);
        $subject = Subject::create([
            'code' => 'LEAD 1',
            'title' => 'Leadership Seminar 1',
            'units' => 1.5,
            'college' => CollegeCode::Ccs,
            'status' => SubjectStatus::Active,
        ]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => '1st',
            'is_required' => true,
        ]);

        $response = $this->withToken($this->tokenForFaculty())
            ->getJson('/api/v1/faculty-preference-catalog');

        $response->assertOk()
            ->assertJsonPath('data.0.curriculum_id', $curriculum->id)
            ->assertJsonPath('data.0.semesters.0.subjects.0.code', 'LEAD 1')
            ->assertJsonPath('data.0.semesters.0.subjects.0.units', 1.5);
    }
}
