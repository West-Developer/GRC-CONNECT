<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stakeholder Doc 14: a student picks sections without seeing the professor,
 * and the professor appears only after the enrollment and add/drop windows are
 * over. The API withholds it; hiding it in the UI would not be enough.
 */
final class ProfessorHiddenFromStudentsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private AcademicTerm $term;

    private Section $section;

    private StudentProfile $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
            'enrollment_closes_at' => now()->addDays(3),
            'add_drop_deadline_at' => now()->addDays(10),
        ]);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'CS101 Title', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
        ]);
        $professor = User::create([
            'name' => 'Prof. Reveal Later', 'email' => 'prof.reveal@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $this->section = Section::create([
            'academic_term_id' => $this->term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'professor_id' => $professor->id, 'capacity' => 40, 'status' => SectionStatus::Published,
        ]);
        $user = User::create([
            'name' => 'Hidden Student', 'email' => 'student.hidden@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $this->student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-0001',
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function loginStaff(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Staff '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return $this->login($email);
    }

    private function revealTheProfessor(): void
    {
        $this->term->update([
            'enrollment_closes_at' => now()->subDays(20),
            'add_drop_deadline_at' => now()->subDays(1),
        ]);
    }

    /** @return array<string, mixed> */
    private function sectionRowFor(string $token): array
    {
        return collect(
            $this->withToken($token)->getJson('/api/v1/sections?academic_term_id='.$this->term->id)->assertOk()->json('data'),
        )->firstWhere('id', $this->section->id);
    }

    public function test_a_student_does_not_see_the_professor_on_sections_during_the_windows(): void
    {
        $row = $this->sectionRowFor($this->login($this->student->user->email));

        $this->assertNull($row['professor_name']);
        $this->assertNull($row['professor_id']);
    }

    public function test_a_student_does_not_see_the_professor_in_the_eligible_subject_picker(): void
    {
        $token = $this->login($this->student->user->email);

        $entry = collect(
            $this->withToken($token)->getJson('/api/v1/eligible-subjects?academic_term_id='.$this->term->id)->assertOk()->json('data'),
        )->firstWhere('code', 'CS101');

        $this->assertNotEmpty($entry['available_sections']);
        $this->assertNull($entry['available_sections'][0]['professor_name']);
    }

    public function test_a_student_does_not_see_the_professor_on_their_own_enrollment_yet(): void
    {
        $token = $this->login($this->student->user->email);
        $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $this->term->id,
            'sections' => [['section_id' => $this->section->id]],
        ])->assertCreated()->assertJsonPath('data.subjects.0.professor_name', null);

        $this->withToken($token)->getJson('/api/v1/enrollments')
            ->assertOk()
            ->assertJsonPath('data.0.subjects.0.professor_name', null);
    }

    /**
     * One role per test: chaining `withToken()` for different users inside a
     * single test silently keeps the first user (a documented Sanctum test gotcha).
     */
    public function test_registrar_staff_still_see_the_professor_during_the_windows(): void
    {
        $token = $this->loginStaff(UserRole::RegistrarStaff, 'staff.reveal.registrar@grc.test');

        $row = $this->sectionRowFor($token);

        $this->assertSame('Prof. Reveal Later', $row['professor_name']);
        $this->assertNotNull($row['professor_id']);
    }

    public function test_a_program_head_still_sees_the_professor_during_the_windows(): void
    {
        $token = $this->loginStaff(UserRole::ProgramChair, 'staff.reveal.head@grc.test');

        $row = $this->sectionRowFor($token);

        $this->assertSame('Prof. Reveal Later', $row['professor_name']);
    }

    public function test_the_professor_appears_once_both_windows_have_passed(): void
    {
        $this->revealTheProfessor();

        $row = $this->sectionRowFor($this->login($this->student->user->email));

        $this->assertSame('Prof. Reveal Later', $row['professor_name']);
        $this->assertNotNull($row['professor_id']);
    }

    public function test_a_closed_term_always_shows_the_professor_to_students(): void
    {
        $this->term->update([
            'status' => AcademicTermStatus::SemesterClosed,
            'enrollment_closes_at' => now()->addDays(30),
            'add_drop_deadline_at' => now()->addDays(60),
        ]);

        $row = $this->sectionRowFor($this->login($this->student->user->email));

        $this->assertSame('Prof. Reveal Later', $row['professor_name']);
    }

    public function test_with_no_configured_dates_an_ongoing_term_keeps_the_professor_hidden(): void
    {
        $this->term->update(['enrollment_closes_at' => null, 'add_drop_deadline_at' => null]);

        $row = $this->sectionRowFor($this->login($this->student->user->email));

        $this->assertNull($row['professor_name']);
    }
}
