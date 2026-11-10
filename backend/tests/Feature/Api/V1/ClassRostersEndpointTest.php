<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClassRostersEndpointTest extends TestCase
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

    private function makeSubject(string $code = 'CS101'): Subject
    {
        return Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => 3.0, 'status' => SubjectStatus::Active]);
    }

    private function makeStudent(Curriculum $curriculum, string $email = 'student.roster@grc.test', string $studentNumber = '2026-0001'): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $studentNumber,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function makeSection(AcademicTerm $term, Subject $subject, ?User $professor, string $sectionCode = 'A'): Section
    {
        return Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => $sectionCode,
            'professor_id' => $professor?->id,
            'capacity' => 40,
            'status' => SectionStatus::Published,
        ]);
    }

    private function makeRosterEntry(StudentProfile $student, AcademicTerm $term, Section $section, EnrollmentSubjectStatus $status = EnrollmentSubjectStatus::Enrolled): EnrollmentSubject
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled,
            'total_units' => 3,
            'submitted_at' => now(),
        ]);

        return EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
            'status' => $status,
        ]);
    }

    private function tokenForNewUser(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/class-rosters')->assertUnauthorized();
    }

    public function test_a_student_cannot_view_the_class_roster(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->getJson('/api/v1/class-rosters')->assertForbidden();
    }

    public function test_a_faculty_member_sees_only_their_own_sections_roster(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $ownProfessor = User::create(['name' => 'Owner', 'email' => 'prof.ownroster@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $ownSection = $this->makeSection($term, $this->makeSubject('CS101'), $ownProfessor);
        $student = $this->makeStudent($curriculum);
        $this->makeRosterEntry($student, $term, $ownSection);

        $otherProfessor = User::create(['name' => 'Other', 'email' => 'prof.otherroster@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $otherSection = $this->makeSection($term, $this->makeSubject('CS102'), $otherProfessor);
        $otherStudent = $this->makeStudent($curriculum, 'other.student.roster@grc.test', '2026-0002');
        $this->makeRosterEntry($otherStudent, $term, $otherSection);

        $token = $this->tokenFor($ownProfessor);

        $response = $this->withToken($token)->getJson('/api/v1/class-rosters');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.student_number', $student->student_number);
        $response->assertJsonPath('data.0.subject_code', 'CS101');
        $response->assertJsonPath('data.0.section_code', $ownSection->section_code);
        $response->assertJsonPath('data.0.status', 'enrolled');
    }

    public function test_registrar_staff_and_registrar_head_see_every_section(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professorA = User::create(['name' => 'A', 'email' => 'prof.a.roster@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $sectionA = $this->makeSection($term, $this->makeSubject('CS101'), $professorA);
        $studentA = $this->makeStudent($curriculum);
        $this->makeRosterEntry($studentA, $term, $sectionA);

        $professorB = User::create(['name' => 'B', 'email' => 'prof.b.roster@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $sectionB = $this->makeSection($term, $this->makeSubject('CS102'), $professorB);
        $studentB = $this->makeStudent($curriculum, 'student.b.roster@grc.test', '2026-0002');
        $this->makeRosterEntry($studentB, $term, $sectionB);

        $registrarStaffToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar-staff.roster@grc.test');
        $staffView = $this->withToken($registrarStaffToken)->getJson('/api/v1/class-rosters');
        $staffView->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_registrar_head_sees_every_section(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professorA = User::create(['name' => 'A', 'email' => 'prof.a.headroster@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $sectionA = $this->makeSection($term, $this->makeSubject('CS101'), $professorA);
        $studentA = $this->makeStudent($curriculum);
        $this->makeRosterEntry($studentA, $term, $sectionA);

        $professorB = User::create(['name' => 'B', 'email' => 'prof.b.headroster@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $sectionB = $this->makeSection($term, $this->makeSubject('CS102'), $professorB);
        $studentB = $this->makeStudent($curriculum, 'student.b.headroster@grc.test', '2026-0002');
        $this->makeRosterEntry($studentB, $term, $sectionB);

        $registrarHeadToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar-head.roster@grc.test');
        $headView = $this->withToken($registrarHeadToken)->getJson('/api/v1/class-rosters');
        $headView->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_roster_can_be_filtered_by_section_id(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = User::create(['name' => 'Prof', 'email' => 'prof.filterroster@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $sectionA = $this->makeSection($term, $this->makeSubject('CS101'), $professor, 'A');
        $sectionB = $this->makeSection($term, $this->makeSubject('CS102'), $professor, 'B');
        $studentA = $this->makeStudent($curriculum);
        $this->makeRosterEntry($studentA, $term, $sectionA);
        $studentB = $this->makeStudent($curriculum, 'student.b.filterroster@grc.test', '2026-0002');
        $this->makeRosterEntry($studentB, $term, $sectionB);

        $token = $this->tokenFor($professor);

        $response = $this->withToken($token)->getJson("/api/v1/class-rosters?section_id={$sectionA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.section_id', $sectionA->id);
    }
}
