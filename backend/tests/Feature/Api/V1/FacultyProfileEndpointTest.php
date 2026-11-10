<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\FacultyEmploymentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionModality;
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

/**
 * Stakeholder Doc 14 S17: the Registrar Head reads a professor's teaching
 * profile: terms taught, sections with their schedule, and grade-submission
 * counts. Read-only, and no student is named.
 */
final class FacultyProfileEndpointTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(UserRole $role, ?CollegeCode $college = null, ?FacultyEmploymentType $type = null): User
    {
        return User::create([
            'name' => 'Test '.$role->value.' '.++$this->seq,
            'email' => $role->value.'.'.$this->seq.'.profile@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'college' => $college,
            'employment_type' => $type,
            'status' => UserStatus::Active,
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('faculty-profile-test')->plainTextToken;
    }

    private function term(string $year, string $semester, AcademicTermStatus $status): AcademicTerm
    {
        return AcademicTerm::create(['school_year' => $year, 'semester' => $semester, 'status' => $status]);
    }

    private function section(AcademicTerm $term, User $professor, string $code, float $units): Section
    {
        $subject = Subject::create(['code' => $code, 'college' => CollegeCode::Ccs, 'title' => 'Title '.$code, 'units' => $units, 'status' => SubjectStatus::Active]);

        return Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'professor_id' => $professor->id,
            'schedule_days' => 'MON',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
            'room' => 'R1',
            'modality' => SectionModality::FaceToFace,
            'capacity' => 40,
            'enrolled_count' => 12,
            'status' => SectionStatus::Published,
        ]);
    }

    private function grade(Section $section, int $studentNumber, GradeStatus $status, User $encoder): void
    {
        $program = Program::query()->first()
            ?? Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $curriculum = Curriculum::query()->first()
            ?? Curriculum::create(['program_id' => $program->id, 'name' => 'Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);
        $studentUser = User::create([
            'name' => 'Private Student '.$studentNumber,
            'email' => "student{$studentNumber}@grc.test",
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);
        $profile = StudentProfile::create([
            'user_id' => $studentUser->id,
            'student_number' => 'SN-'.$studentNumber,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
        AcademicGrade::create([
            'student_id' => $profile->id,
            'subject_id' => $section->subject_id,
            'section_id' => $section->id,
            'academic_term_id' => $section->academic_term_id,
            'status' => $status,
            'encoded_by' => $encoder->id,
        ]);
    }

    public function test_the_registrar_head_reads_terms_sections_units_and_grade_counts(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs, FacultyEmploymentType::FullTime);
        $registrarHead = $this->user(UserRole::RegistrarHead);
        $old = $this->term('2025-2026', '2nd', AcademicTermStatus::Archived);
        $current = $this->term('2026-2027', '1st', AcademicTermStatus::SemesterOngoing);
        $this->section($old, $professor, 'OLD101', 3);
        $one = $this->section($current, $professor, 'CUR101', 3);
        $this->section($current, $professor, 'CUR102', 2);
        $this->grade($one, 1, GradeStatus::Draft, $professor);
        $this->grade($one, 2, GradeStatus::Submitted, $professor);
        $this->grade($one, 3, GradeStatus::Locked, $professor);
        $this->grade($one, 4, GradeStatus::Locked, $professor);

        $response = $this->withToken($this->token($registrarHead))
            ->getJson("/api/v1/faculty-members/{$professor->id}/profile");

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.professor.id', $professor->id)
            ->assertJsonPath('data.professor.employment_type', 'full_time')
            ->assertJsonPath('data.professor.employment_type_label', 'Full-time')
            ->assertJsonPath('data.selected_term.academic_term_id', $current->id)
            ->assertJsonPath('data.selected_term.total_units', 5)
            ->assertJsonCount(2, 'data.selected_term.sections')
            ->assertJsonCount(2, 'data.terms')
            ->assertJsonPath('data.terms.0.academic_term_id', $current->id)
            ->assertJsonPath('data.terms.0.total_units', 5)
            ->assertJsonPath('data.terms.1.academic_term_id', $old->id)
            ->assertJsonPath('data.terms.1.total_units', 3);

        $row = collect($response->json('data.selected_term.sections'))->firstWhere('section_id', $one->id);
        self::assertSame('CUR101', $row['subject_code']);
        self::assertSame(12, $row['enrolled_count']);
        self::assertSame(['draft' => 1, 'submitted' => 1, 'locked' => 2, 'total' => 4], $row['grades']);

        // Counts only: nothing that names a student.
        $response->assertDontSee('Private Student');
        $response->assertDontSee('SN-1');
    }

    public function test_another_term_can_be_chosen(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $registrarHead = $this->user(UserRole::RegistrarHead);
        $old = $this->term('2025-2026', '2nd', AcademicTermStatus::Archived);
        $this->term('2026-2027', '1st', AcademicTermStatus::SemesterOngoing);
        $this->section($old, $professor, 'OLD201', 3);

        $this->withToken($this->token($registrarHead))
            ->getJson("/api/v1/faculty-members/{$professor->id}/profile?academic_term_id={$old->id}")
            ->assertOk()
            ->assertJsonPath('data.selected_term.academic_term_id', $old->id)
            ->assertJsonCount(1, 'data.selected_term.sections');
    }

    public function test_an_unknown_term_is_rejected(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $registrarHead = $this->user(UserRole::RegistrarHead);

        $this->withToken($this->token($registrarHead))
            ->getJson("/api/v1/faculty-members/{$professor->id}/profile?academic_term_id=99999")
            ->assertUnprocessable();
    }

    public function test_a_professor_who_teaches_nothing_gets_an_empty_profile(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $registrarHead = $this->user(UserRole::RegistrarHead);

        $this->withToken($this->token($registrarHead))
            ->getJson("/api/v1/faculty-members/{$professor->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.terms', [])
            ->assertJsonPath('data.selected_term', null);
    }

    public function test_only_a_faculty_account_can_be_profiled(): void
    {
        $student = $this->user(UserRole::Student);
        $registrarHead = $this->user(UserRole::RegistrarHead);

        $this->withToken($this->token($registrarHead))
            ->getJson("/api/v1/faculty-members/{$student->id}/profile")
            ->assertForbidden();
    }

    public function test_program_heads_professors_and_registrar_staff_are_forbidden(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);

        foreach ([UserRole::ProgramChair, UserRole::Faculty, UserRole::RegistrarStaff, UserRole::Dean] as $role) {
            $this->withToken($this->token($this->user($role, CollegeCode::Ccs)))
                ->getJson("/api/v1/faculty-members/{$professor->id}/profile")
                ->assertForbidden();
            $this->flushHeaders();
        }
    }
}
