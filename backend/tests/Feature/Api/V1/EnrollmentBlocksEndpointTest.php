<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\TransfereeCreditStatus;
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
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\StudentSchedulePreference;
use App\Models\Subject;
use App\Models\SubjectPrerequisite;
use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers `GET /enrollment-blocks` — the blocks a regular student may enrol
 * into as a unit. See `App\Actions\Enrollment\BuildEnrollmentBlockPool`.
 */
final class EnrollmentBlocksEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTerm(AcademicTermStatus $status = AcademicTermStatus::SemesterOngoing): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => $status,
            'enrollment_opens_at' => now()->subDay(), 'enrollment_closes_at' => now()->addWeek(),
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

    private function makeStudent(Curriculum $curriculum, int $yearLevel = 1, ?string $enrollmentCategory = null, string $email = 'student.block@grc.test'): StudentProfile
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
            'year_level' => $yearLevel,
            'enrollment_category' => $enrollmentCategory,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function makePlan(AcademicTerm $term, Curriculum $curriculum, int $yearLevel = 1): AcademicTermSectionPlan
    {
        return AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => $yearLevel, 'section_count' => 1,
            'students_per_block' => 40, 'status' => 'submitted',
        ]);
    }

    /**
     * @return array{0: Subject, 1: Section}
     */
    private function makeBlockSection(AcademicTerm $term, AcademicTermSectionPlan $plan, string $blockCode, string $subjectCode, array $overrides = []): array
    {
        $subject = Subject::create(['code' => $subjectCode, 'title' => $subjectCode.' Title', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create([
            'curriculum_id' => $plan->curriculum_id, 'subject_id' => $subject->id,
            'year_level' => $plan->year_level, 'semester' => '1st', 'is_required' => true,
        ]);
        $faculty = User::create([
            'name' => 'Prof '.$subjectCode, 'email' => strtolower($subjectCode).'.prof@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        $section = Section::create(array_merge([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $subject->id,
            'section_code' => $blockCode,
            'professor_id' => $faculty->id,
            'schedule_days' => 'MWF',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
            'room' => 'LAB-1',
            'capacity' => 40,
            'is_block_exclusive' => true,
            'status' => SectionStatus::Published,
        ], $overrides));

        return [$subject, $section];
    }

    private function tokenFor(StudentProfile $student): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $student->user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_a_regular_student_sees_their_own_year_blocks_with_every_subject(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101');
        $this->makeBlockSection($term, $plan, 'IT101', 'GE101');
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.block_code', 'IT101');
        $response->assertJsonPath('data.0.is_selectable', true);
        $response->assertJsonPath('data.0.seats_remaining', 40);
        self::assertSame(6.0, (float) $response->json('data.0.total_units'));
        self::assertCount(2, $response->json('data.0.subjects'));
    }

    public function test_a_dual_semester_subject_already_passed_does_not_block_selecting_the_block(): void
    {
        // Real-data regression: a subject placed '1st|2nd' (offered either
        // semester by curriculum design) that a student already passed in
        // an earlier semester must not disqualify them from their current
        // block — see ClassifyEnrollmentStandingTest's matching case for
        // the classification side of the same bug (E-COMM,
        // curriculum_id=10, blocked most of the term's Irregular
        // population including a student with no genuine backlog).
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        [$subject] = $this->makeBlockSection($term, $plan, 'IT101', 'CS101');
        CurriculumSubject::where('curriculum_id', $curriculum->id)->where('subject_id', $subject->id)->update(['semester' => '1st|2nd']);
        $student = $this->makeStudent($curriculum);
        $registrar = User::create(['name' => 'Registrar', 'email' => 'registrar.dualsem@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'academic_term_id' => $term->id,
            'mark' => '2.00', 'status' => GradeStatus::Locked, 'encoded_by' => $registrar->id,
        ]);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertJsonPath('data.0.is_selectable', true);
        self::assertSame([], $response->json('data.0.reasons'));
    }

    public function test_seats_remaining_is_the_minimum_across_every_section_in_the_block(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101', ['capacity' => 1, 'enrolled_count' => 1]);
        $this->makeBlockSection($term, $plan, 'IT101', 'GE101', ['capacity' => 40]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertJsonPath('data.0.seats_remaining', 0);
        $response->assertJsonPath('data.0.is_selectable', false);
        $response->assertJsonPath('data.0.reasons.0.code', 'block_full');
    }

    public function test_an_irregular_student_receives_an_empty_pool(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101');
        // A genuine backlog subject (year 1, while she is in year 2) outside the block, with an open
        // section this term — this is what actually makes her Irregular
        // under the term-scoped rule now that BuildEnrollmentAccessContext
        // self-heals on every read.
        $backlog = Subject::create(['code' => 'CS-BACKLOG', 'title' => 'Backlog', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $backlog->id,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
        ]);
        Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $backlog->id, 'section_code' => 'A',
            'capacity' => 40, 'is_block_exclusive' => false, 'status' => SectionStatus::Published,
        ]);
        $student = $this->makeStudent($curriculum, yearLevel: 2, enrollmentCategory: 'irregular');
        $token = $this->tokenFor($student);
        User::create([
            'name' => 'Registrar', 'email' => 'registrar.blockpool@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_a_block_from_another_year_level_is_not_returned(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $secondYearPlan = $this->makePlan($term, $curriculum, yearLevel: 2);
        $this->makeBlockSection($term, $secondYearPlan, 'IT201', 'CS201');
        $student = $this->makeStudent($curriculum, yearLevel: 1);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertJsonCount(0, 'data');
    }

    public function test_a_closed_window_marks_the_block_unselectable_but_still_visible(): void
    {
        $term = $this->makeTerm(AcademicTermStatus::Draft);
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101');
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.is_selectable', false);
        self::assertContains(
            'window_closed',
            array_column($response->json('data.0.reasons'), 'code'),
        );
    }

    private function credit(StudentProfile $student, Subject $subject, TransfereeCreditStatus $status = TransfereeCreditStatus::Approved): void
    {
        TransfereeCredit::create([
            'student_id' => $student->id, 'source_institution' => 'Other University',
            'source_subject_code' => 'EXT101', 'source_subject_title' => 'Previous school subject',
            'credited_units' => 3, 'subject_id' => $subject->id, 'status' => $status,
        ]);
    }

    /**
     * Owner, 2026-10-15: a transferee credited for NSTP 1 was still treated as Regular and offered the whole
     * block, NSTP 1 included. A subject the student is credited for cannot be enrolled again, so the student
     * is Irregular and enrols per subject: no block is offered, and the category on the profile says so.
     */
    public function test_a_student_credited_for_a_subject_of_the_block_becomes_irregular_and_is_offered_no_block(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        [$credited] = $this->makeBlockSection($term, $plan, 'IT101', 'NSTP1');
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101');
        $student = $this->makeStudent($curriculum, 1, 'regular');
        $this->credit($student, $credited);
        // The category heals itself on use, attributed to a Registrar Head, as it does in production.
        User::create([
            'name' => 'Registrar Head', 'email' => 'head.block@grc.test', 'password' => self::PASSWORD,
            'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        $this->withToken($this->tokenFor($student))
            ->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        self::assertSame('irregular', $student->fresh()->enrollment_category);
    }

    public function test_a_block_cannot_be_submitted_by_a_student_credited_for_one_of_its_subjects(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        [$credited] = $this->makeBlockSection($term, $plan, 'IT101', 'NSTP1');
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101');
        $student = $this->makeStudent($curriculum, 1, 'regular');
        $this->credit($student, $credited);

        $this->withToken($this->tokenFor($student))
            ->postJson('/api/v1/enrollments', ['academic_term_id' => $term->id, 'block_code' => 'IT101'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_a_credit_on_a_subject_outside_the_block_leaves_a_regular_student_with_a_selectable_block(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101');
        $elsewhere = Subject::create(['code' => 'ZZ900', 'title' => 'Not in this block', 'units' => 3, 'status' => SubjectStatus::Active]);
        $student = $this->makeStudent($curriculum, 1, 'regular');
        $this->credit($student, $elsewhere);

        $this->withToken($this->tokenFor($student))
            ->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.0.is_selectable', true);

        self::assertSame('regular', $student->fresh()->enrollment_category);
    }

    /**
     * The classifier counts a credited prerequisite as met, so a student it calls Regular for that reason must
     * not then find the block withheld because the block pool only looked at grades.
     */
    public function test_a_credited_prerequisite_does_not_withhold_the_block_from_a_regular_student(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        [$dependent] = $this->makeBlockSection($term, $plan, 'IT101', 'CS201');
        $prerequisite = Subject::create(['code' => 'CS100', 'title' => 'Introduction', 'units' => 3, 'status' => SubjectStatus::Active]);
        $placement = CurriculumSubject::query()->where('curriculum_id', $curriculum->id)->where('subject_id', $dependent->id)->firstOrFail();
        SubjectPrerequisite::create([
            'curriculum_subject_id' => $placement->id, 'prerequisite_subject_id' => $prerequisite->id, 'minimum_grade' => '3.00',
        ]);
        $student = $this->makeStudent($curriculum, 1, 'regular');
        $this->credit($student, $prerequisite);

        $this->withToken($this->tokenFor($student))
            ->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.0.is_selectable', true);
    }

    public function test_an_incomplete_schedule_marks_the_block_unselectable(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101', ['schedule_days' => null]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertJsonPath('data.0.is_selectable', false);
        self::assertContains(
            'incomplete_schedule',
            array_column($response->json('data.0.reasons'), 'code'),
        );
    }

    public function test_a_section_without_an_assigned_room_remains_selectable(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101', ['room' => null]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertJsonPath('data.0.is_selectable', true);
    }

    public function test_a_section_without_an_assigned_professor_remains_selectable(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101', ['professor_id' => null]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertJsonPath('data.0.is_selectable', true);
        $response->assertJsonPath('data.0.subjects.0.professor_name', null);
    }

    public function test_a_student_without_a_saved_preference_still_receives_every_block_with_a_null_score(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101');
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.preference_score', null);
        $response->assertJsonPath('data.0.preference_reasons', []);
    }

    public function test_a_saved_preference_scores_a_matching_block_higher(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        $this->makeBlockSection($term, $plan, 'IT101', 'CS101', [
            'schedule_days' => 'MWF', 'starts_at_time' => '09:00:00', 'ends_at_time' => '10:00:00',
        ]);
        $student = $this->makeStudent($curriculum);
        StudentSchedulePreference::create([
            'student_id' => $student->id,
            'preferred_days' => [1, 3, 5],
            'preferred_time_block' => 'morning',
            'max_days_on_campus' => 3,
            'avoid_early_first_class' => true,
        ]);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertJsonCount(1, 'data');
        self::assertGreaterThan(0, $response->json('data.0.preference_score'));
        self::assertContains('No class before 8:00 AM', $response->json('data.0.preference_reasons'));
    }

    public function test_a_non_block_exclusive_section_does_not_appear_as_a_block(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum);
        $this->makeBlockSection($term, $plan, 'A', 'CS101', ['is_block_exclusive' => false]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id);

        $response->assertJsonCount(0, 'data');
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/enrollment-blocks?academic_term_id=1')->assertUnauthorized();
    }

    public function test_a_non_student_role_is_forbidden(): void
    {
        $term = $this->makeTerm();
        $registrar = User::create([
            'name' => 'Registrar', 'email' => 'registrar.blocks@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $registrar->email, 'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/enrollment-blocks?academic_term_id='.$term->id)->assertForbidden();
    }
}
