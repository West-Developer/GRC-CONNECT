<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditAction;
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
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
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

final class SectionGradesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_faculty_sees_only_assigned_classes_with_correct_grading_progress(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera@grc.test');
        $otherProfessor = $this->makeUser(UserRole::Faculty, 'Professor Santos', 'santos@grc.test');
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $ownSection = $this->makeSection($term, $subject, $professor, 'BSCS-1A');
        $otherSection = $this->makeSection($term, $this->makeSubject('CS102', 'Programming I'), $otherProfessor, 'BSCS-1B');

        $gradedStudent = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0001', 'ada@grc.test');
        $ungradedStudent = $this->makeStudent($curriculum, 'Grace Hopper', '2026-0002', 'grace@grc.test');
        $otherStudent = $this->makeStudent($curriculum, 'Alan Turing', '2026-0003', 'alan@grc.test');
        $this->enroll($gradedStudent, $term, $ownSection);
        $this->enroll($ungradedStudent, $term, $ownSection);
        $this->enroll($otherStudent, $term, $otherSection);

        AcademicGrade::create([
            'student_id' => $gradedStudent->id,
            'subject_id' => $subject->id,
            'section_id' => $ownSection->id,
            'academic_term_id' => $term->id,
            'mark' => '1.50',
            'final_grade' => '1.50',
            'status' => GradeStatus::Draft,
            'encoded_by' => $professor->id,
        ]);

        $response = $this->withToken($this->tokenFor($professor))
            ->getJson('/api/v1/sections/grade-submission');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.section_id', $ownSection->id);
        $response->assertJsonPath('data.0.subject.code', 'CS101');
        $response->assertJsonPath('data.0.subject.title', 'Introduction to Computing');
        $response->assertJsonPath('data.0.academic_term.school_year', '2026-2027');
        $response->assertJsonPath('data.0.academic_term.semester', '1st');
        $response->assertJsonPath('data.0.schedule.days', 'MWF');
        $response->assertJsonPath('data.0.enrolled_count', 2);
        $response->assertJsonPath('data.0.recorded_count', 1);
        $response->assertJsonPath('data.0.missing_count', 1);
        $response->assertJsonPath('data.0.state', 'in_progress');
    }

    public function test_grade_sheet_includes_only_the_exact_enrolled_roster_with_student_names(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.sheet@grc.test');
        $subject = $this->makeSubject('CS201', 'Data Structures');
        $section = $this->makeSection($term, $subject, $professor, 'BSCS-2A');

        $enrolled = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0101', 'ada.sheet@grc.test');
        $dropped = $this->makeStudent($curriculum, 'Grace Hopper', '2026-0102', 'grace.sheet@grc.test');
        $pending = $this->makeStudent($curriculum, 'Alan Turing', '2026-0103', 'alan.sheet@grc.test');
        $this->enroll($enrolled, $term, $section);
        $this->enroll($dropped, $term, $section, EnrollmentStatus::Enrolled, EnrollmentSubjectStatus::Dropped);
        $this->enroll($pending, $term, $section, EnrollmentStatus::PendingPayment);

        $response = $this->withToken($this->tokenFor($professor))
            ->getJson("/api/v1/sections/{$section->id}/grades");

        $response->assertOk()->assertJsonCount(1, 'data.rows');
        $response->assertJsonPath('data.section.section_id', $section->id);
        $response->assertJsonPath('data.rows.0.student_id', $enrolled->id);
        $response->assertJsonPath('data.rows.0.student_number', '2026-0101');
        $response->assertJsonPath('data.rows.0.student_name', 'Ada Lovelace');
        $response->assertJsonPath('data.rows.0.mark', null);
        $response->assertJsonPath('data.rows.0.status', 'not_recorded');
        $response->assertJsonMissingPath('data.rows.0.email');
        $response->assertJsonMissingPath('data.rows.0.address');
    }

    public function test_bulk_draft_save_creates_and_updates_partial_rows_idempotently(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.save@grc.test');
        $subject = $this->makeSubject('CS301', 'Algorithms');
        $section = $this->makeSection($term, $subject, $professor, 'BSCS-3A');
        $ada = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0201', 'ada.save@grc.test');
        $grace = $this->makeStudent($curriculum, 'Grace Hopper', '2026-0202', 'grace.save@grc.test');
        $this->enroll($ada, $term, $section);
        $this->enroll($grace, $term, $section);
        $token = $this->tokenFor($professor);

        $this->withToken($token)->postJson("/api/v1/sections/{$section->id}/grades", [
            'grades' => [[
                'student_id' => $ada->id,
                'mark' => '1.50',
                'remarks' => 'Strong work',
            ]],
        ])->assertOk()
            ->assertJsonPath('data.section.recorded_count', 1)
            ->assertJsonPath('data.section.missing_count', 1);

        $response = $this->withToken($token)->postJson("/api/v1/sections/{$section->id}/grades", [
            'grades' => [
                ['student_id' => $ada->id, 'mark' => '2.00', 'remarks' => 'Rechecked'],
                ['student_id' => $grace->id, 'mark' => '3.00'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.section.recorded_count', 2);
        $this->assertDatabaseCount('academic_grades', 2);
        $this->assertDatabaseHas('academic_grades', [
            'student_id' => $ada->id,
            'subject_id' => $subject->id,
            'academic_term_id' => $term->id,
            'mark' => '2.00',
            'remarks' => 'Rechecked',
            'status' => GradeStatus::Draft->value,
        ]);
        $this->assertDatabaseHas('academic_grades', [
            'student_id' => $grace->id,
            'mark' => '3.00',
            'status' => GradeStatus::Draft->value,
        ]);
        self::assertSame(2, AuditLog::query()->where('action', AuditAction::ACADEMIC_GRADE_CREATED)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::ACADEMIC_GRADE_UPDATED)->count());
    }

    public function test_final_submission_rejects_an_incomplete_roster_without_transitioning_any_grade(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.incomplete@grc.test');
        $subject = $this->makeSubject('CS401', 'Software Engineering');
        $section = $this->makeSection($term, $subject, $professor, 'BSCS-4A');
        $ada = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0301', 'ada.incomplete@grc.test');
        $grace = $this->makeStudent($curriculum, 'Grace Hopper', '2026-0302', 'grace.incomplete@grc.test');
        $this->enroll($ada, $term, $section);
        $this->enroll($grace, $term, $section);
        $grade = AcademicGrade::create([
            'student_id' => $ada->id,
            'subject_id' => $subject->id,
            'section_id' => $section->id,
            'academic_term_id' => $term->id,
            'mark' => '1.75',
            'final_grade' => '1.75',
            'status' => GradeStatus::Draft,
            'encoded_by' => $professor->id,
        ]);

        $response = $this->withToken($this->tokenFor($professor))
            ->postJson("/api/v1/sections/{$section->id}/grades/submit");

        $response->assertUnprocessable()->assertJsonPath(
            'error.errors.grades.0',
            'Every enrolled student must have a valid grade before final submission.',
        );
        self::assertSame(GradeStatus::Draft, $grade->refresh()->status);
        self::assertNull($grade->submitted_at);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::ACADEMIC_GRADE_SUBMITTED)->count());
    }

    public function test_complete_final_submission_transitions_the_whole_section_once(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.complete@grc.test');
        $subject = $this->makeSubject('CS402', 'Capstone');
        $section = $this->makeSection($term, $subject, $professor, 'BSCS-4B');
        $ada = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0401', 'ada.complete@grc.test');
        $grace = $this->makeStudent($curriculum, 'Grace Hopper', '2026-0402', 'grace.complete@grc.test');
        $this->enroll($ada, $term, $section);
        $this->enroll($grace, $term, $section);
        $this->makeDraftGrade($ada, $subject, $section, $term, $professor, '1.25');
        $this->makeDraftGrade($grace, $subject, $section, $term, $professor, '2.25');
        $token = $this->tokenFor($professor);

        $response = $this->withToken($token)
            ->postJson("/api/v1/sections/{$section->id}/grades/submit");

        $response->assertOk()
            ->assertJsonPath('data.section.state', 'submitted')
            ->assertJsonPath('data.section.submitted_count', 2)
            ->assertJsonPath('data.rows.0.status', 'submitted')
            ->assertJsonPath('data.rows.1.status', 'submitted');

        $submittedGrades = AcademicGrade::query()->orderBy('id')->get();
        self::assertCount(2, $submittedGrades);
        self::assertNotNull($submittedGrades[0]->submitted_at);
        self::assertTrue($submittedGrades[0]->submitted_at->equalTo($submittedGrades[1]->submitted_at));
        self::assertSame(2, AuditLog::query()->where('action', AuditAction::ACADEMIC_GRADE_SUBMITTED)->count());

        $this->withToken($token)
            ->postJson("/api/v1/sections/{$section->id}/grades/submit")
            ->assertOk()
            ->assertJsonPath('data.section.state', 'submitted');

        self::assertSame(2, AuditLog::query()->where('action', AuditAction::ACADEMIC_GRADE_SUBMITTED)->count());
    }

    public function test_empty_roster_cannot_be_submitted(): void
    {
        $term = $this->makeTerm();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.empty@grc.test');
        $section = $this->makeSection(
            $term,
            $this->makeSubject('CS403', 'Distributed Systems'),
            $professor,
            'BSCS-4C',
        );

        $response = $this->withToken($this->tokenFor($professor))
            ->postJson("/api/v1/sections/{$section->id}/grades/submit");

        $response->assertUnprocessable()->assertJsonPath(
            'error.errors.grades.0',
            'This section has no enrolled students to submit.',
        );
    }

    public function test_non_owning_faculty_cannot_access_a_section_grade_sheet(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.owner@grc.test');
        $otherProfessor = $this->makeUser(UserRole::Faculty, 'Professor Santos', 'santos.nonowner@grc.test');
        $section = $this->makeSection(
            $term,
            $this->makeSubject('CS404', 'Information Security'),
            $professor,
            'BSCS-4D',
        );
        $student = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0451', 'ada.owner@grc.test');
        $this->enroll($student, $term, $section);

        $otherToken = $otherProfessor->createToken('test-faculty')->plainTextToken;
        $this->withToken($otherToken)->getJson("/api/v1/sections/{$section->id}/grades")->assertForbidden();
        $this->withToken($otherToken)->postJson("/api/v1/sections/{$section->id}/grades", [
            'grades' => [['student_id' => $student->id, 'mark' => '1.00']],
        ])->assertForbidden();
        $this->withToken($otherToken)->postJson("/api/v1/sections/{$section->id}/grades/submit")->assertForbidden();
    }

    public function test_non_faculty_role_cannot_list_grade_submission_sections(): void
    {
        $accounting = $this->makeUser(UserRole::AccountingStaff, 'Cashier', 'cashier.grades@grc.test');

        $this->withToken($this->tokenFor($accounting))
            ->getJson('/api/v1/sections/grade-submission')
            ->assertForbidden();
    }

    public function test_bulk_save_rejects_invalid_duplicate_and_non_roster_rows(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.invalid@grc.test');
        $subject = $this->makeSubject('LEAD 1', 'Leadership');
        $section = $this->makeSection($term, $subject, $professor, 'BSCS-1L');
        $enrolled = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0501', 'ada.invalid@grc.test');
        $outsider = $this->makeStudent($curriculum, 'Grace Hopper', '2026-0502', 'grace.invalid@grc.test');
        $this->enroll($enrolled, $term, $section);
        $token = $this->tokenFor($professor);

        $invalid = $this->withToken($token)->postJson("/api/v1/sections/{$section->id}/grades", [
            'grades' => [
                ['student_id' => $enrolled->id, 'mark' => '1.00'],
                ['student_id' => $enrolled->id, 'mark' => 'C'],
            ],
        ]);
        $invalid->assertUnprocessable();
        self::assertArrayHasKey('grades.0.mark', $invalid->json('error.errors'));
        self::assertArrayHasKey('grades.1.student_id', $invalid->json('error.errors'));

        $nonRoster = $this->withToken($token)->postJson("/api/v1/sections/{$section->id}/grades", [
            'grades' => [['student_id' => $outsider->id, 'mark' => 'C']],
        ]);
        $nonRoster->assertUnprocessable();
        self::assertSame(
            'The student is not enrolled in this section.',
            $nonRoster->json('error.errors')['grades.0.student_id'][0] ?? null,
        );
        $this->assertDatabaseCount('academic_grades', 0);
    }

    public function test_bulk_save_rejects_changes_to_submitted_or_locked_grades(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.readonly@grc.test');
        $subject = $this->makeSubject('CS405', 'Compiler Design');
        $section = $this->makeSection($term, $subject, $professor, 'BSCS-4E');
        $student = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0601', 'ada.readonly@grc.test');
        $this->enroll($student, $term, $section);
        $grade = $this->makeDraftGrade($student, $subject, $section, $term, $professor, '2.00');
        $grade->update(['status' => GradeStatus::Submitted, 'submitted_at' => now()]);

        $response = $this->withToken($this->tokenFor($professor))
            ->postJson("/api/v1/sections/{$section->id}/grades", [
                'grades' => [['student_id' => $student->id, 'mark' => '1.00']],
            ]);

        $response->assertUnprocessable();
        self::assertSame('2.00', $grade->refresh()->mark->value);
        self::assertSame(GradeStatus::Submitted, $grade->status);
    }

    public function test_legacy_individual_submit_delegates_to_complete_section_submission(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.legacy-submit@grc.test');
        $subject = $this->makeSubject('CS406', 'Machine Learning');
        $section = $this->makeSection($term, $subject, $professor, 'BSCS-4F');
        $ada = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0701', 'ada.legacy-submit@grc.test');
        $grace = $this->makeStudent($curriculum, 'Grace Hopper', '2026-0702', 'grace.legacy-submit@grc.test');
        $this->enroll($ada, $term, $section);
        $this->enroll($grace, $term, $section);
        $first = $this->makeDraftGrade($ada, $subject, $section, $term, $professor, '1.50');
        $second = $this->makeDraftGrade($grace, $subject, $section, $term, $professor, '2.50');

        $response = $this->withToken($this->tokenFor($professor))
            ->patchJson("/api/v1/academic-grades/{$first->id}", ['action' => 'submit']);

        $response->assertOk()->assertJsonPath('data.status', 'submitted');
        self::assertSame(GradeStatus::Submitted, $first->refresh()->status);
        self::assertSame(GradeStatus::Submitted, $second->refresh()->status);
        self::assertSame(2, AuditLog::query()->where('action', AuditAction::ACADEMIC_GRADE_SUBMITTED)->count());
    }

    public function test_legacy_individual_submit_cannot_create_a_partial_submitted_roster(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.legacy-incomplete@grc.test');
        $subject = $this->makeSubject('CS409', 'Applied Computing');
        $section = $this->makeSection($term, $subject, $professor, 'BSCS-4H');
        $ada = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0751', 'ada.legacy-incomplete@grc.test');
        $grace = $this->makeStudent($curriculum, 'Grace Hopper', '2026-0752', 'grace.legacy-incomplete@grc.test');
        $this->enroll($ada, $term, $section);
        $this->enroll($grace, $term, $section);
        $grade = $this->makeDraftGrade($ada, $subject, $section, $term, $professor, '1.75');

        $response = $this->withToken($this->tokenFor($professor))
            ->patchJson("/api/v1/academic-grades/{$grade->id}", ['action' => 'submit']);

        $response->assertUnprocessable()->assertJsonPath(
            'error.errors.grades.0',
            'Every enrolled student must have a valid grade before final submission.',
        );
        self::assertSame(GradeStatus::Draft, $grade->refresh()->status);
        self::assertNull($grade->submitted_at);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::ACADEMIC_GRADE_SUBMITTED)->count());
    }

    public function test_legacy_create_rejects_mismatched_section_metadata_and_non_roster_student(): void
    {
        $term = $this->makeTerm();
        $otherTerm = $this->makeTerm('2027-2028', '2nd');
        $curriculum = $this->makeCurriculum();
        $professor = $this->makeUser(UserRole::Faculty, 'Professor Rivera', 'rivera.legacy-create@grc.test');
        $sectionSubject = $this->makeSubject('CS407', 'Operating Systems');
        $otherSubject = $this->makeSubject('CS408', 'Networks');
        $section = $this->makeSection($term, $sectionSubject, $professor, 'BSCS-4G');
        $outsider = $this->makeStudent($curriculum, 'Ada Lovelace', '2026-0801', 'ada.legacy-create@grc.test');

        $response = $this->withToken($this->tokenFor($professor))->postJson('/api/v1/academic-grades', [
            'student_id' => $outsider->id,
            'subject_id' => $otherSubject->id,
            'section_id' => $section->id,
            'academic_term_id' => $otherTerm->id,
            'mark' => '1.00',
        ]);

        $response->assertUnprocessable();
        $errors = $response->json('error.errors');
        self::assertArrayHasKey('student_id', $errors);
        self::assertArrayHasKey('subject_id', $errors);
        self::assertArrayHasKey('academic_term_id', $errors);
        $this->assertDatabaseCount('academic_grades', 0);
    }

    private function makeTerm(string $schoolYear = '2026-2027', string $semester = '1st'): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => $schoolYear,
            'semester' => $semester,
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'status' => ProgramStatus::Active,
        ]);

        return Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeSubject(string $code, string $title): Subject
    {
        return Subject::create([
            'code' => $code,
            'title' => $title,
            'units' => 3.0,
            'status' => SubjectStatus::Active,
        ]);
    }

    private function makeSection(
        AcademicTerm $term,
        Subject $subject,
        User $professor,
        string $sectionCode,
    ): Section {
        return Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => $sectionCode,
            'professor_id' => $professor->id,
            'schedule_days' => 'MWF',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
            'capacity' => 40,
            'status' => SectionStatus::Published,
        ]);
    }

    private function makeUser(UserRole $role, string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function makeStudent(
        Curriculum $curriculum,
        string $name,
        string $studentNumber,
        string $email,
    ): StudentProfile {
        $user = $this->makeUser(UserRole::Student, $name, $email);

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

    private function enroll(
        StudentProfile $student,
        AcademicTerm $term,
        Section $section,
        EnrollmentStatus $enrollmentStatus = EnrollmentStatus::Enrolled,
        EnrollmentSubjectStatus $subjectStatus = EnrollmentSubjectStatus::Enrolled,
    ): EnrollmentSubject {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => $enrollmentStatus,
            'total_units' => 3,
            'submitted_at' => now(),
            'enrolled_at' => $enrollmentStatus === EnrollmentStatus::Enrolled ? now() : null,
        ]);

        return EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
            'status' => $subjectStatus,
        ]);
    }

    private function makeDraftGrade(
        StudentProfile $student,
        Subject $subject,
        Section $section,
        AcademicTerm $term,
        User $professor,
        string $mark,
    ): AcademicGrade {
        return AcademicGrade::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'section_id' => $section->id,
            'academic_term_id' => $term->id,
            'mark' => $mark,
            'final_grade' => $mark,
            'status' => GradeStatus::Draft,
            'encoded_by' => $professor->id,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }
}
