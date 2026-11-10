<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GradeSlipEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeSubject(string $code, float $units = 3.0): Subject
    {
        return Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => $units, 'status' => SubjectStatus::Active]);
    }

    private function makeStudent(Curriculum $curriculum, string $email = 'student.gradeslip@grc.test'): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'STU-'.$user->id,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 2,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function tokenForNewUser(UserRole $role, string $email): string
    {
        $user = User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return $this->tokenFor($user);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/grade-slip?academic_term_id=1')->assertUnauthorized();
    }

    public function test_academic_term_id_is_required(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->getJson('/api/v1/grade-slip')->assertStatus(422);
    }

    public function test_a_student_sees_their_own_grade_slip_with_correct_totals(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $professor = User::create(['name' => 'Prof', 'email' => 'prof.gradeslip@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);

        $numericSubject = $this->makeSubject('CS101', 3.0);
        $leadSubject = $this->makeSubject('LEAD 6', 1.5);
        $numericSection = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $numericSubject->id, 'section_code' => 'A',
            'professor_id' => $professor->id, 'capacity' => 40, 'status' => SectionStatus::Published,
        ]);

        AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $numericSubject->id, 'section_id' => $numericSection->id,
            'academic_term_id' => $term->id, 'mark' => '1.75', 'status' => GradeStatus::Locked, 'encoded_by' => $professor->id,
        ]);
        // Section-less locked grade -- must render, not crash.
        AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $leadSubject->id, 'section_id' => null,
            'academic_term_id' => $term->id, 'mark' => 'NC', 'status' => GradeStatus::Locked, 'encoded_by' => $professor->id,
        ]);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->getJson('/api/v1/grade-slip?academic_term_id='.$term->id);

        $response->assertOk();
        self::assertSame(4.5, $response->json('data.total_academic_units'));
        // A whole-number float (3.0) round-trips through JSON as 3, not
        // "3.0" -- assertSame(3.0, ...) would fail on the decoded int.
        self::assertEqualsWithDelta(3.0, $response->json('data.gpa_units'), 0.0);
        self::assertSame('1.75', $response->json('data.gpa'));
        self::assertSame(1, $response->json('data.excluded_from_gpa_count'));

        $rows = $response->json('data.rows');
        self::assertCount(2, $rows);
        $numericRow = collect($rows)->firstWhere('code', 'CS101');
        self::assertSame('A', $numericRow['section_code']);
        self::assertSame('Prof', $numericRow['professor_name']);
        self::assertTrue($numericRow['counts_toward_gpa']);

        $leadRow = collect($rows)->firstWhere('code', 'LEAD 6');
        self::assertNull($leadRow['section_code']);
        self::assertNull($leadRow['professor_name']);
        self::assertFalse($leadRow['counts_toward_gpa']);
    }

    public function test_a_student_cannot_view_another_students_grade_slip(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $owner = $this->makeStudent($curriculum, 'owner.gradeslip@grc.test');
        $other = $this->makeStudent($curriculum, 'other.gradeslip@grc.test');
        $token = $this->tokenFor($other->user);

        $this->withToken($token)
            ->getJson('/api/v1/grade-slip?academic_term_id='.$term->id.'&student_id='.$owner->id)
            ->assertForbidden();
    }

    public function test_a_registrar_head_can_view_any_students_grade_slip(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $registrarToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar.gradeslip@grc.test');

        $this->withToken($registrarToken)
            ->getJson('/api/v1/grade-slip?academic_term_id='.$term->id.'&student_id='.$student->id)
            ->assertOk();
    }

    public function test_a_faculty_role_is_forbidden(): void
    {
        $term = $this->makeTerm();
        $token = $this->tokenForNewUser(UserRole::Faculty, 'faculty.gradeslip@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/grade-slip?academic_term_id='.$term->id)
            ->assertForbidden();
    }
}
