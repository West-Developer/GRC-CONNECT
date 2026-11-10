<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GraduateEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/graduates');
        $response->assertStatus(401);
    }

    public function test_student_cannot_view_graduates_list(): void
    {
        $student = User::create([
            'name' => 'Test Student',
            'email' => 'student.test@grc.test',
            'password' => 'password',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($student);

        $response = $this->getJson('/api/v1/graduates');
        $response->assertStatus(403);
    }

    public function test_registrar_head_can_view_and_filter_graduates(): void
    {
        $registrar = User::create([
            'name' => 'Registrar Head',
            'email' => 'reg.head@grc.test',
            'password' => 'password',
            'role' => UserRole::RegistrarHead,
            'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($registrar);

        $program = Program::query()->firstOrCreate(
            ['code' => 'BSIT'],
            ['name' => 'BS Information Technology', 'status' => 'active', 'college' => 'ccs']
        );

        $curriculum = Curriculum::query()->firstOrCreate(
            ['program_id' => $program->id, 'effective_start_year' => 2018],
            ['name' => 'BSIT 2018-2023', 'effective_school_year' => '2018-2023', 'status' => 'archived']
        );

        $studentUser = User::create([
            'name' => 'DELA CRUZ, JUAN A.',
            'first_name' => 'JUAN',
            'last_name' => 'DELA CRUZ',
            'email' => 'juan.delacruz@grc.test',
            'password' => 'password',
            'role' => UserRole::Student,
            'status' => UserStatus::Disabled,
        ]);

        $profile = StudentProfile::create([
            'user_id' => $studentUser->id,
            'student_number' => '2018-00001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2018,
            'year_level' => 4,
            'admission_status' => AdmissionStatus::Graduated,
            'graduation_school_year' => '2021-2022',
            'academic_standing' => 'good',
        ]);

        $term = AcademicTerm::query()->firstOrCreate(
            ['school_year' => '2021-2022', 'semester' => '2nd'],
            ['status' => 'archived']
        );

        $subject = Subject::query()->firstOrCreate(
            ['college' => 'ccs', 'code' => 'TEST101'],
            ['title' => 'Test Subject', 'units' => 3, 'status' => 'active']
        );

        AcademicGrade::create([
            'student_id' => $profile->id,
            'subject_id' => $subject->id,
            'academic_term_id' => $term->id,
            'final_grade' => 1.50,
            'mark' => '1.50',
            'status' => 'locked',
            'encoded_by' => $registrar->id,
        ]);

        $response = $this->getJson('/api/v1/graduates');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'student_number',
                        'full_name',
                        'program_code',
                        'program_name',
                        'curriculum_version',
                        'graduation_school_year',
                        'final_gpa',
                    ],
                ],
                'summary' => ['total_graduates'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonFragment([
                'student_number' => '2018-00001',
                'graduation_school_year' => '2021-2022',
                'final_gpa' => 1.5,
            ]);

        // Test filtering by school year
        $filterResponse = $this->getJson('/api/v1/graduates?graduation_school_year=2021-2022');
        $filterResponse->assertStatus(200)
            ->assertJsonFragment(['student_number' => '2018-00001']);

        // Test filtering with non-matching school year
        $emptyResponse = $this->getJson('/api/v1/graduates?graduation_school_year=2015-2016');
        $emptyResponse->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
