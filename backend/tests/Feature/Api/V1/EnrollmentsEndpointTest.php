<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentAudience;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AcademicTermEnrollmentWindow;
use App\Models\AcademicTermSectionPlan;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnrollmentsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeCurriculum(string $programCode = 'BSCS'): Curriculum
    {
        $program = Program::create(['code' => $programCode, 'name' => $programCode.' Program', 'status' => ProgramStatus::Active]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => $programCode.' Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeSubject(string $code, float $units = 3.0): Subject
    {
        return Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => $units, 'status' => SubjectStatus::Active]);
    }

    private function placeSubject(Curriculum $curriculum, Subject $subject): CurriculumSubject
    {
        return CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
        ]);
    }

    private function makeSection(AcademicTerm $term, Subject $subject, array $overrides = []): Section
    {
        return Section::create(array_merge([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'capacity' => 40,
            'status' => SectionStatus::Published,
        ], $overrides));
    }

    private function makeStudent(Curriculum $curriculum): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => 'student.enroll@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2026-0001',
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    /**
     * @return array{0: AcademicTermSectionPlan, 1: list<Section>}
     */
    private function makeBlock(AcademicTerm $term, Curriculum $curriculum, string $blockCode, array $subjectCodes): array
    {
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => 1, 'section_count' => 1,
            'students_per_block' => 40, 'status' => 'submitted',
        ]);

        $sections = [];
        // Real block schedules never overlap; staggering the start hour per
        // subject keeps this fixture conflict-free the same way.
        foreach (array_values($subjectCodes) as $index => $subjectCode) {
            $subject = $this->makeSubject($subjectCode);
            $this->placeSubject($curriculum, $subject);
            $startHour = 8 + $index;
            $sections[] = Section::create([
                'academic_term_id' => $term->id,
                'section_plan_id' => $plan->id,
                'subject_id' => $subject->id,
                'section_code' => $blockCode,
                'schedule_days' => 'MWF',
                'starts_at_time' => sprintf('%02d:00:00', $startHour),
                'ends_at_time' => sprintf('%02d:00:00', $startHour + 1),
                'room' => 'LAB-1',
                'professor_id' => User::create([
                    'name' => 'Prof '.$subjectCode, 'email' => strtolower($subjectCode).'.prof@grc.test',
                    'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
                ])->id,
                'capacity' => 40,
                'is_block_exclusive' => true,
                'status' => SectionStatus::Published,
            ]);
        }

        return [$plan, $sections];
    }

    private function tokenFor(StudentProfile $student): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $student->user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function makeStudentWithEmail(Curriculum $curriculum, string $email, string $studentNumber): StudentProfile
    {
        $user = User::create([
            'name' => 'Another Student', 'email' => $email,
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

    private function tokenForNewStaff(UserRole $role, string $email): string
    {
        User::create([
            'name' => $role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    /**
     * Creates an enrollment directly via Eloquent rather than through the
     * submission endpoint. Chaining `withToken()` for two *different* users
     * within one test method silently reuses the first user's cached guard
     * resolution (a documented Sanctum test gotcha) — so every visibility
     * test below authenticates as exactly one actor and seeds every other
     * student's data directly instead of via a second login+submit.
     */
    private function makeEnrollment(StudentProfile $student, AcademicTerm $term, EnrollmentStatus $status = EnrollmentStatus::PendingRegistrarApproval): Enrollment
    {
        return Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => $status,
            'total_units' => 3.0,
            'submitted_at' => now(),
        ]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/enrollments')->assertUnauthorized();
        $this->postJson('/api/v1/enrollments', [])->assertUnauthorized();
    }

    public function test_a_non_student_role_is_forbidden(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        User::create([
            'name' => 'Faculty', 'email' => 'faculty.enroll@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'faculty.enroll@grc.test', 'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/enrollments')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ])->assertForbidden();
    }

    public function test_a_valid_submission_atomically_creates_everything_exactly_once(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101', 3.0);
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        // An unclassified student picking subjects one by one is checked by the
        // Program Head first (ADR 0030), then by the Registrar.
        $response->assertJsonPath('data.status', 'pending_program_head_approval');
        $response->assertJsonPath('data.total_units', 3);
        $response->assertJsonCount(1, 'data.subjects');
        $response->assertJsonPath('data.subjects.0.subject_code', 'CS101');
        // No queue ticket yet — it is issued only once Registrar Staff
        // approves (Phase 6), not at submission.
        $response->assertJsonPath('data.queue_ticket', null);

        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('enrollment_subjects', 1);
        $this->assertDatabaseCount('queue_tickets', 0);
        self::assertSame(AuditAction::ENROLLMENT_SUBMITTED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
        self::assertSame(
            NotificationType::EnrollmentSubmitted,
            Notification::query()->sole()->type,
        );

        $section->refresh();
        self::assertSame(1, $section->enrolled_count);
    }

    private function makeRegistrarUser(UserRole $role, string $email): User
    {
        return User::create([
            'name' => 'Notify '.$role->value,
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    public function test_the_enrollment_resource_has_the_exact_key_set(): void
    {
        // Nothing else in this suite pins EnrollmentResource's full shape —
        // it is otherwise enforced only by a PHPDoc, docs/api/openapi.yaml,
        // and the frontend's .strict() Zod schema, none of which run
        // against the backend. A key added here without this test still
        // passing green would silently break the live frontend at runtime.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101', 3.0);
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);

        $response->assertCreated();
        self::assertSame(
            [
                'type', 'id', 'student_id', 'student_number', 'student_name', 'student_year_level',
                'student_financial_status', 'student_financial_status_label',
                'student_enrollment_category', 'is_irregular', 'is_late_enrollee', 'academic_term_id',
                'status', 'status_label', 'total_units', 'requires_overload_approval', 'regular_unit_maximum', 'unit_maximum', 'exceeds_unit_maximum', 'program_head_comment',
                'submitted_at', 'program_head_decided_at', 'registrar_decided_at', 'payment_confirmed_at', 'enrolled_at',
                'subjects', 'revisions', 'queue_ticket', 'assessment',
            ],
            array_keys($response->json('data')),
        );
        $response->assertJsonPath('data.student_name', null);
        $response->assertJsonPath('data.student_year_level', null);
        self::assertSame(
            [
                'section_id', 'section_code', 'subject_id', 'subject_code', 'paired_subject_id', 'subject_title',
                'units', 'schedule_days', 'starts_at_time', 'ends_at_time',
                'room', 'modality', 'professor_name', 'status', 'status_label',
            ],
            array_keys($response->json('data.subjects.0')),
        );
    }

    public function test_a_lecture_and_its_paired_lab_round_trip_their_subject_and_pair_ids(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $lecture = $this->makeSubject('ITP1 LEC', 2.0);
        $lab = $this->makeSubject('ITP1 LAB', 1.0);
        $lecture->update(['paired_subject_id' => $lab->id]);
        $lab->update(['paired_subject_id' => $lecture->id]);
        $this->placeSubject($curriculum, $lecture);
        $this->placeSubject($curriculum, $lab);
        $lectureSection = $this->makeSection($term, $lecture);
        $labSection = $this->makeSection($term, $lab);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [
                ['section_id' => $lectureSection->id],
                ['section_id' => $labSection->id],
            ],
        ]);

        $response->assertCreated();
        $subjects = collect($response->json('data.subjects'));
        $lectureRow = $subjects->firstWhere('subject_code', 'ITP1 LEC');
        $labRow = $subjects->firstWhere('subject_code', 'ITP1 LAB');
        self::assertSame($lecture->id, $lectureRow['subject_id']);
        self::assertSame($lab->id, $lectureRow['paired_subject_id']);
        self::assertSame($lab->id, $labRow['subject_id']);
        self::assertSame($lecture->id, $labRow['paired_subject_id']);
    }

    public function test_a_block_submission_enrolls_every_subject_in_the_block_atomically(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        [, $sections] = $this->makeBlock($term, $curriculum, 'IT101', ['CS101', 'GE101']);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'block_code' => 'IT101',
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data.subjects');
        $this->assertDatabaseCount('enrollment_subjects', 2);
        self::assertSame(AuditAction::ENROLLMENT_SUBMITTED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
        self::assertSame('IT101', AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->after_values['block_code'] ?? null);

        foreach ($sections as $section) {
            self::assertSame(1, $section->refresh()->enrolled_count);
        }
    }

    public function test_a_regular_student_submitting_a_prescribed_block_with_heavy_units_goes_to_the_registrar(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum('BSA');
        // 10 subjects of 3.0 units + 1 subject of 0.5 units = 30.5 units (exact BSA Year 1 Sem 1 curriculum load)
        $codes = ['ACT101', 'ACT102', 'ACT103', 'ACT104', 'ACT105', 'ACT106', 'ACT107', 'ACT108', 'ACT109', 'ACT110', 'ACT111'];
        [, $sections] = $this->makeBlock($term, $curriculum, 'BSA101', $codes);
        $sections[10]->subject->update(['units' => 0.5]);

        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'block_code' => 'BSA101',
        ]);

        $response->assertCreated();
        // Registrar approval is back for regular students (ADR 0030): no
        // auto-approval, no assessment and no queue ticket until she approves.
        $response->assertJsonPath('data.status', 'pending_registrar_approval');
        $response->assertJsonPath('data.total_units', 30.5);
        $response->assertJsonPath('data.requires_overload_approval', false);
        $response->assertJsonPath('data.assessment', null);
        $response->assertJsonPath('data.queue_ticket', null);
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_a_regular_submission_notifies_registrar_staff_and_the_registrar_head_but_nobody_else(): void
    {
        $staff = $this->makeRegistrarUser(UserRole::RegistrarStaff, 'registrar.staff.notify@grc.test');
        $head = $this->makeRegistrarUser(UserRole::RegistrarHead, 'registrar.head.notify@grc.test');
        $accounting = $this->makeRegistrarUser(UserRole::AccountingStaff, 'accounting.notify@grc.test');
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum('BSA');
        [, $sections] = $this->makeBlock($term, $curriculum, 'BSA101', ['ACT101']);
        $student = $this->makeStudent($curriculum);

        $this->withToken($this->tokenFor($student))->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'block_code' => 'BSA101',
        ])->assertCreated();

        foreach ([$staff, $head] as $registrar) {
            $notification = Notification::query()->where('user_id', $registrar->id)->sole();
            self::assertSame(NotificationType::EnrollmentSubmitted, $notification->type);
            self::assertStringContainsString($student->student_number, $notification->message);
            self::assertStringContainsString('awaiting Registrar approval', $notification->message);
        }
        $this->assertDatabaseMissing('notifications', ['user_id' => $accounting->id]);
    }

    public function test_a_server_resolved_block_submission_is_not_rejected_for_a_schedule_conflict_between_its_own_subjects(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        [, $sections] = $this->makeBlock($term, $curriculum, 'IT101', ['CS101', 'GE101']);
        $sections[1]->update([
            'schedule_days' => 'MWF',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
        ]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'block_code' => 'IT101',
        ]);

        $response->assertCreated()->assertJsonCount(2, 'data.subjects');
    }

    public function test_sections_and_block_code_together_are_rejected(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        [, $sections] = $this->makeBlock($term, $curriculum, 'IT101', ['CS101']);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'block_code' => 'IT101',
            'sections' => [['section_id' => $sections[0]->id]],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('enrollments', 0);
    }

    /**
     * A block submission deliberately skips the per-subject
     * `rejectIneligibleSections()` check — running the per-subject pool
     * would reject any subject the student already completed, which is a
     * different rule than the one that actually governs a block.
     * `BuildEnrollmentBlockPool` is what's authoritative for a block
     * instead, and it withholds the whole block once any subject in it is
     * already passed: a repeater no longer advances in lockstep with a
     * single block, so they need the Registrar to reclassify them as
     * irregular before they can pick subjects individually.
     */
    public function test_a_previously_completed_subject_blocks_the_whole_block_submission(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        [, $sections] = $this->makeBlock($term, $curriculum, 'IT101', ['CS101', 'GE101']);
        $student = $this->makeStudent($curriculum);
        $faculty = User::create([
            'name' => 'Grade Encoder', 'email' => 'encoder.repeat@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        AcademicGrade::create([
            'student_id' => $student->id,
            'subject_id' => $sections[0]->subject_id,
            'academic_term_id' => $term->id,
            'final_grade' => '1.00',
            'status' => GradeStatus::Locked,
            'encoded_by' => $faculty->id,
        ]);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'block_code' => 'IT101',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('enrollments', 0);
        foreach ($sections as $section) {
            self::assertSame(0, $section->refresh()->enrolled_count);
        }
    }

    public function test_an_unavailable_block_code_is_rejected(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'block_code' => 'DOES-NOT-EXIST',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.errors.block_code.0', 'This section is not available for your year level and curriculum.');
    }

    public function test_submission_against_a_term_that_is_not_semester_ongoing_is_rejected(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::Draft,
        ]);
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('academic_term_id', $response->json('error.errors'));
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_submission_before_the_students_year_level_window_opens_is_rejected(): void
    {
        $term = $this->makeTerm();
        $term->update([
            'enrollment_opens_at' => now()->subDay(),
            'enrollment_closes_at' => now()->addMonth(),
        ]);
        AcademicTermEnrollmentWindow::create([
            'academic_term_id' => $term->id,
            'audience' => EnrollmentAudience::Year1,
            'opens_at' => now()->addWeek(),
            'closes_at' => now()->addMonth(),
        ]);
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey('academic_term_id', $response->json('error.errors'));
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_submission_inside_the_students_year_level_window_succeeds(): void
    {
        $term = $this->makeTerm();
        $term->update([
            'enrollment_opens_at' => now()->subDay(),
            'enrollment_closes_at' => now()->addMonth(),
        ]);
        AcademicTermEnrollmentWindow::create([
            'academic_term_id' => $term->id,
            'audience' => EnrollmentAudience::Year1,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addMonth(),
        ]);
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);

        $response->assertCreated();
    }

    public function test_a_submission_totals_fractional_units_correctly(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $lecture = $this->makeSubject('CS101', 3.0);
        $leadership = $this->makeSubject('LEAD 1', 1.5);
        $this->placeSubject($curriculum, $lecture);
        $this->placeSubject($curriculum, $leadership);
        $sectionA = $this->makeSection($term, $lecture, ['section_code' => 'A']);
        $sectionB = $this->makeSection($term, $leadership, [
            'section_code' => 'B', 'schedule_days' => 'Th', 'starts_at_time' => '10:00:00', 'ends_at_time' => '11:00:00',
        ]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $sectionA->id], ['section_id' => $sectionB->id]],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.total_units', 4.5);
        self::assertSame(4.5, Enrollment::query()->sole()->total_units);
    }

    public function test_a_duplicate_active_enrollment_is_rejected(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id, 'status' => EnrollmentStatus::Draft,
        ]);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('academic_term_id', $response->json('error.errors'));
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_an_ineligible_section_is_rejected(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $subject);
        // Planned, not published -> not eligible.
        $section = $this->makeSection($term, $subject, ['status' => SectionStatus::Planned]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('sections.0.section_id', $response->json('error.errors'));
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_two_sections_for_the_same_subject_are_rejected(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $subject);
        $sectionA = $this->makeSection($term, $subject, ['section_code' => 'A']);
        $sectionB = $this->makeSection($term, $subject, ['section_code' => 'B']);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $sectionA->id], ['section_id' => $sectionB->id]],
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('sections.1.section_id', $response->json('error.errors'));
    }

    public function test_submitting_a_lecture_without_its_paired_laboratory_is_rejected(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $lab = $this->makeSubject('PROG1L', 2.0);
        $lecture = Subject::create([
            'code' => 'PROG1', 'title' => 'PROG1 Title', 'units' => 1.0,
            'status' => SubjectStatus::Active, 'paired_subject_id' => $lab->id,
        ]);
        $lab->forceFill(['paired_subject_id' => $lecture->id])->save();
        $this->placeSubject($curriculum, $lecture);
        $this->placeSubject($curriculum, $lab);
        $lectureSection = $this->makeSection($term, $lecture, ['section_code' => 'A']);
        $this->makeSection($term, $lab, [
            'section_code' => 'A', 'schedule_days' => 'Th', 'starts_at_time' => '10:00:00', 'ends_at_time' => '11:00:00',
        ]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $lectureSection->id]],
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('sections.0.section_id', $response->json('error.errors'));
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_submitting_a_lecture_and_laboratory_in_mismatched_sections_is_rejected(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $lab = $this->makeSubject('PROG1L', 2.0);
        $lecture = Subject::create([
            'code' => 'PROG1', 'title' => 'PROG1 Title', 'units' => 1.0,
            'status' => SubjectStatus::Active, 'paired_subject_id' => $lab->id,
        ]);
        $lab->forceFill(['paired_subject_id' => $lecture->id])->save();
        $this->placeSubject($curriculum, $lecture);
        $this->placeSubject($curriculum, $lab);
        $lectureSection = $this->makeSection($term, $lecture, ['section_code' => 'A']);
        $labSection = $this->makeSection($term, $lab, [
            'section_code' => 'B', 'schedule_days' => 'Th', 'starts_at_time' => '10:00:00', 'ends_at_time' => '11:00:00',
        ]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $lectureSection->id], ['section_id' => $labSection->id]],
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('sections.0.section_id', $response->json('error.errors'));
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_submitting_a_matched_lecture_and_laboratory_pair_succeeds(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $lab = $this->makeSubject('PROG1L', 2.0);
        $lecture = Subject::create([
            'code' => 'PROG1', 'title' => 'PROG1 Title', 'units' => 1.0,
            'status' => SubjectStatus::Active, 'paired_subject_id' => $lab->id,
        ]);
        $lab->forceFill(['paired_subject_id' => $lecture->id])->save();
        $this->placeSubject($curriculum, $lecture);
        $this->placeSubject($curriculum, $lab);
        $lectureSection = $this->makeSection($term, $lecture, ['section_code' => 'A']);
        $labSection = $this->makeSection($term, $lab, [
            'section_code' => 'A', 'schedule_days' => 'Th', 'starts_at_time' => '10:00:00', 'ends_at_time' => '11:00:00',
        ]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $lectureSection->id], ['section_id' => $labSection->id]],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.total_units', 3);
    }

    public function test_conflicting_sections_cannot_be_submitted_together(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subjectA = $this->makeSubject('CS101');
        $subjectB = $this->makeSubject('CS102');
        $this->placeSubject($curriculum, $subjectA);
        $this->placeSubject($curriculum, $subjectB);
        $sectionA = $this->makeSection($term, $subjectA, [
            'schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
        ]);
        $sectionB = $this->makeSection($term, $subjectB, [
            'schedule_days' => 'MWF', 'starts_at_time' => '08:30:00', 'ends_at_time' => '09:30:00',
        ]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $sectionA->id], ['section_id' => $sectionB->id]],
        ]);

        $response->assertUnprocessable();
        $errors = $response->json('error.errors');
        self::assertArrayHasKey('sections.0.section_id', $errors);
        self::assertArrayHasKey('sections.1.section_id', $errors);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_a_duplicate_submission_does_not_double_reserve_seats(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $first = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);
        $first->assertCreated();

        $second = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);
        $second->assertStatus(422);

        $section->refresh();
        self::assertSame(1, $section->enrolled_count);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_the_academic_term_id_and_sections_are_required(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', []);

        $response->assertUnprocessable();
        $errors = $response->json('error.errors');
        self::assertArrayHasKey('academic_term_id', $errors);
        self::assertArrayHasKey('sections', $errors);
    }

    public function test_an_overload_is_rejected_only_when_a_unit_cap_is_configured(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101', 3.0);
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        config(['enrollment.max_regular_units' => 2, 'enrollment.overload_max_units' => null]);

        $overLimit = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);
        $overLimit->assertUnprocessable();
        self::assertArrayHasKey('units_confirmed', $overLimit->json('error.errors'));
        $this->assertDatabaseCount('enrollments', 0);

        config(['enrollment.max_regular_units' => null, 'enrollment.overload_max_units' => null]);

        $unconfigured = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);
        $unconfigured->assertCreated();
    }

    public function test_a_submission_within_the_overload_allowance_is_permitted_but_flagged(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101', 3.0);
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        config(['enrollment.max_regular_units' => 2, 'enrollment.overload_max_units' => 4]);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.requires_overload_approval', true);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_an_irregular_student_beyond_the_unit_maximum_is_asked_to_confirm_then_goes_to_the_program_chair_flagged(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101', 3.0);
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        config(['enrollment.max_regular_units' => 1, 'enrollment.overload_max_units' => 2]);

        // Without the student's "I am sure" it is not submitted, and the message names the units and the maximum.
        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ]);

        $response->assertUnprocessable();
        self::assertStringContainsString('3 units, over the 2 unit maximum', $response->json('error.errors.units_confirmed.0'));
        $this->assertDatabaseCount('enrollments', 0);

        // Once they confirm, it is no longer refused: it goes to the Program Chair, flagged as over the maximum.
        $confirmed = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
            'units_confirmed' => true,
        ]);

        $confirmed->assertCreated();
        $confirmed->assertJsonPath('data.status', 'pending_program_head_approval');
        $confirmed->assertJsonPath('data.requires_overload_approval', true);
        $confirmed->assertJsonPath('data.unit_maximum', 2);
        $confirmed->assertJsonPath('data.exceeds_unit_maximum', true);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_the_list_can_be_narrowed_to_the_enrollments_over_the_unit_maximum(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $big = $this->makeSubject('CS101', 3.0);
        $small = $this->makeSubject('CS102', 1.0);
        $this->placeSubject($curriculum, $big);
        $this->placeSubject($curriculum, $small);
        $bigSection = $this->makeSection($term, $big);
        $smallSection = $this->makeSection($term, $small);
        $over = $this->makeStudent($curriculum);
        $within = $this->makeStudentWithEmail($curriculum, 'within.max@grc.test', '2026-0002');
        config(['enrollment.max_regular_units' => 1, 'enrollment.overload_max_units' => 2]);

        $overToken = $this->tokenFor($over);
        $withinToken = $this->tokenFor($within);
        $this->app['auth']->forgetGuards();
        $this->withToken($overToken)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $bigSection->id]],
            'units_confirmed' => true,
        ])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($withinToken)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $smallSection->id]],
        ])->assertCreated();
        $this->app['auth']->forgetGuards();

        $headToken = $this->tokenForNewStaff(UserRole::RegistrarHead, 'registrar.head.overmax@grc.test');
        $this->app['auth']->forgetGuards();

        $this->withToken($headToken)->getJson('/api/v1/enrollments')->assertOk()->assertJsonCount(2, 'data');
        $narrowed = $this->withToken($headToken)->getJson('/api/v1/enrollments?exceeds_unit_maximum=1')->assertOk();
        self::assertSame(['2026-0001'], collect($narrowed->json('data'))->pluck('student_number')->all());
        self::assertTrue($narrowed->json('data.0.exceeds_unit_maximum'));
    }

    public function test_a_regular_student_still_cannot_pass_the_unit_maximum(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101', 3.0);
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $student->update(['enrollment_category' => 'regular']);
        $token = $this->tokenFor($student);

        config(['enrollment.max_regular_units' => 1, 'enrollment.overload_max_units' => 2]);

        $response = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
            'units_confirmed' => true,
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('sections', $response->json('error.errors'));
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_registrar_approve_is_rejected_without_acknowledging_a_flagged_overload(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
        $enrollment->update(['requires_overload_approval' => true]);
        $staffToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.overloadunacked@grc.test');

        $response = $this->withToken($staffToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve']);

        $response->assertUnprocessable();
        self::assertArrayHasKey('overload_acknowledged', $response->json('error.errors'));
        self::assertSame('pending_registrar_approval', $enrollment->refresh()->status->value);
    }

    public function test_registrar_approve_succeeds_once_a_flagged_overload_is_acknowledged(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
        $enrollment->update(['requires_overload_approval' => true]);
        $staffToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.overloadacked@grc.test');

        $response = $this->withToken($staffToken)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'registrar_approve',
            'overload_acknowledged' => true,
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'pending_payment');
    }

    public function test_registrar_approve_does_not_require_acknowledgement_when_not_flagged(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
        $staffToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.nooverload@grc.test');

        $response = $this->withToken($staffToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve']);

        $response->assertOk()->assertJsonPath('data.status', 'pending_payment');
    }

    public function test_index_returns_only_the_authenticated_students_own_enrollments(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ])->assertCreated();

        $response = $this->withToken($token)->getJson('/api/v1/enrollments');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.status', 'pending_program_head_approval');
    }

    public function test_a_second_students_enrollment_is_not_visible_to_the_first_student(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();

        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);
        $this->makeEnrollment($student, $term);

        $otherStudent = $this->makeStudentWithEmail($curriculum, 'other.student@grc.test', '2026-0002');
        $this->makeEnrollment($otherStudent, $term);

        $response = $this->withToken($token)->getJson('/api/v1/enrollments');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.student_number', $student->student_number);
    }

    public function test_registrar_head_sees_every_enrollment_with_filters_and_pagination(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();

        $studentA = $this->makeStudent($curriculum);
        $this->makeEnrollment($studentA, $term);

        $studentB = $this->makeStudentWithEmail($curriculum, 'second.student@grc.test', '2026-0003');
        $this->makeEnrollment($studentB, $term);

        $registrarToken = $this->tokenForNewStaff(UserRole::RegistrarHead, 'registrar.enroll@grc.test');

        $all = $this->withToken($registrarToken)->getJson('/api/v1/enrollments');
        $all->assertOk()->assertJsonCount(2, 'data');

        $filtered = $this->withToken($registrarToken)->getJson('/api/v1/enrollments?status=pending_registrar_approval');
        $filtered->assertOk()->assertJsonCount(2, 'data');

        $paged = $this->withToken($registrarToken)->getJson('/api/v1/enrollments?per_page=1&page=1');
        $paged->assertOk()->assertJsonCount(1, 'data');
        $paged->assertJsonPath('meta.total', 2);
        $paged->assertJsonPath('meta.per_page', 1);
    }

    public function test_registrar_staff_sees_every_enrollment_with_filters_and_pagination(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();

        $studentA = $this->makeStudent($curriculum);
        $this->makeEnrollment($studentA, $term);

        $studentB = $this->makeStudentWithEmail($curriculum, 'second.staff@grc.test', '2026-0003');
        $this->makeEnrollment($studentB, $term);

        $staffToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.enroll@grc.test');

        $all = $this->withToken($staffToken)->getJson('/api/v1/enrollments');
        $all->assertOk()->assertJsonCount(2, 'data');

        $filtered = $this->withToken($staffToken)->getJson('/api/v1/enrollments?status=pending_registrar_approval');
        $filtered->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_registrar_staff_receives_the_student_name_and_year_for_an_enrollment_review(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $this->makeEnrollment($student, $term);
        $staffToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.context@grc.test');

        $response = $this->withToken($staffToken)->getJson('/api/v1/enrollments');

        $response->assertOk();
        $response->assertJsonPath('data.0.student_name', 'Test Student');
        $response->assertJsonPath('data.0.student_year_level', 1);
    }

    public function test_registrar_staff_can_search_enrollments_by_student_number_and_name(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();

        $studentA = $this->makeStudent($curriculum);
        $this->makeEnrollment($studentA, $term);

        $studentB = $this->makeStudentWithEmail($curriculum, 'maria.santos@grc.test', '2026-9999');
        $studentB->user->update(['name' => 'Maria Santos']);
        $this->makeEnrollment($studentB, $term);

        $staffToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.search@grc.test');

        $byNumber = $this->withToken($staffToken)->getJson('/api/v1/enrollments?search=2026-9999');
        $byNumber->assertOk()->assertJsonCount(1, 'data');
        $byNumber->assertJsonPath('data.0.student_number', '2026-9999');

        $byName = $this->withToken($staffToken)->getJson('/api/v1/enrollments?search=Maria');
        $byName->assertOk()->assertJsonCount(1, 'data');
        $byName->assertJsonPath('data.0.student_number', '2026-9999');
    }

    public function test_accounting_staff_sees_only_pending_payment_enrollments_regardless_of_status_filter(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();

        $student = $this->makeStudent($curriculum);
        $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);

        $paidStudent = $this->makeStudentWithEmail($curriculum, 'paid.student@grc.test', '2026-0004');
        $this->makeEnrollment($paidStudent, $term, EnrollmentStatus::PendingPayment);

        $accountingToken = $this->tokenForNewStaff(UserRole::AccountingStaff, 'accounting.enroll@grc.test');

        $response = $this->withToken($accountingToken)->getJson('/api/v1/enrollments');
        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.student_number', $paidStudent->student_number);
        $response->assertJsonPath('data.0.student_name', null);
        $response->assertJsonPath('data.0.student_year_level', null);

        $stillFiltered = $this->withToken($accountingToken)
            ->getJson('/api/v1/enrollments?status=pending_registrar_approval');
        $stillFiltered->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cashier_can_list_approved_current_students_without_tickets_and_fetch_ticket_enrollments_by_id(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $approved = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingPayment);
        $withTicket = $this->makeEnrollment($this->makeStudentWithEmail($curriculum, 'cashier.ticket@grc.test', '2026-0004'), $term, EnrollmentStatus::PendingPayment);
        $pending = $this->makeEnrollment($this->makeStudentWithEmail($curriculum, 'cashier.pending@grc.test', '2026-0005'), $term, EnrollmentStatus::PendingRegistrarApproval);
        $pastTerm = AcademicTerm::create(['school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived]);
        $this->makeEnrollment($student, $pastTerm, EnrollmentStatus::PendingPayment);
        $cycle = QueueCycle::create(['opened_on' => now()->toDateString(), 'last_ticket_sequence' => 1]);
        QueueTicket::create([
            'enrollment_id' => $withTicket->id, 'ticket_number' => 'Q001', 'queue_date' => now()->toDateString(),
            'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1, 'status' => 'waiting',
        ]);
        $token = $this->tokenForNewStaff(UserRole::AccountingStaff, 'accounting.approved.list@grc.test');

        $this->withToken($token)->getJson('/api/v1/enrollments?status=pending_payment&without_queue_ticket=1&current_term=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $approved->id);
        $this->withToken($token)->getJson('/api/v1/enrollments?enrollment_ids[]='.$withTicket->id.'&enrollment_ids[]='.$pending->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $withTicket->id);
        $this->assertDatabaseCount('queue_tickets', 1);
    }

    public function test_dean_can_list_enrollments_and_receives_student_name_and_year(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $this->makeEnrollment($student, $term);
        $deanToken = $this->tokenForNewStaff(UserRole::Dean, 'dean.enroll@grc.test');

        $response = $this->withToken($deanToken)->getJson('/api/v1/enrollments');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.student_name', 'Test Student');
        $response->assertJsonPath('data.0.student_year_level', 1);
    }

    public function test_executive_director_can_list_enrollments_and_receives_student_name_and_year(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $this->makeEnrollment($student, $term);
        $execToken = $this->tokenForNewStaff(UserRole::ExecutiveDirector, 'exec.enroll@grc.test');

        $response = $this->withToken($execToken)->getJson('/api/v1/enrollments');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.student_name', 'Test Student');
        $response->assertJsonPath('data.0.student_year_level', 1);
    }

    public function test_registrar_approve_transitions_a_pending_approval_enrollment_to_pending_payment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
        $staffToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.approve@grc.test');

        $response = $this->withToken($staffToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve']);

        $response->assertOk()->assertJsonPath('data.status', 'pending_payment');
        self::assertNotNull($enrollment->refresh()->registrar_decided_at);
        self::assertSame(
            AuditAction::ENROLLMENT_REGISTRAR_APPROVED,
            AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action,
        );
        self::assertSame(
            NotificationType::EnrollmentRegistrarApproved,
            Notification::query()->sole()->type,
        );

        // The Cashier queue ticket is no longer issued at this checkpoint
        // -- only at claim (App\Actions\Enrollment\ClaimQueueTicket).
        $response->assertJsonPath('data.queue_ticket', null);
        $this->assertDatabaseCount('queue_tickets', 0);

        // PRD §5.3 process 3.3 "computes the approved assessment" — computed
        // in the same step, folded into the same single audit row (see
        // AssessEnrollment's docblock), not a second AuditLog/Notification.
        $this->assertDatabaseCount('assessments', 1);
        // makeEnrollment() sets total_units = 3.0 and this fixture is BSCS.
        // The reference schedule is PHP 200.00/unit plus PHP 4,200.00 in
        // all-student fees; the PHP 500.00 Lab Fee 2 is deliberately BSIT-only.
        $response->assertJsonPath('data.assessment.total_amount', '4800.00');
        $response->assertJsonCount(15, 'data.assessment.items');
    }

    public function test_registrar_approval_adds_the_bsit_only_second_computer_lab_fee(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum('BSIT');
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
        $staffToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.bsit-fees@grc.test');

        $response = $this->withToken($staffToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve']);

        $response->assertOk()
            ->assertJsonPath('data.assessment.total_amount', '5300.00')
            ->assertJsonCount(16, 'data.assessment.items')
            ->assertJsonFragment([
                'label' => 'Computer Lab Fee 2 (BSIT)',
                'amount' => '500.00',
            ]);
    }

    public function test_a_registrar_head_can_approve_an_enrollment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
        $registrarToken = $this->tokenForNewStaff(UserRole::RegistrarHead, 'registrar.head.approve@grc.test');

        $response = $this->withToken($registrarToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve']);

        $response->assertOk()->assertJsonPath('data.status', 'pending_payment');
        self::assertNotNull($enrollment->refresh()->registrar_decided_at);
        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('queue_tickets', 0);
    }

    public function test_a_program_chair_cannot_perform_the_registrar_approval(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
        $chairToken = $this->tokenForNewStaff(UserRole::ProgramChair, 'chair.noregistrar@grc.test');

        $this->withToken($chairToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve'])
            ->assertForbidden();

        self::assertSame('pending_registrar_approval', $enrollment->refresh()->status->value);
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_registrar_reject_requires_a_reason_and_transitions_to_rejected(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
        $registrarToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.reject@grc.test');

        $withoutReason = $this->withToken($registrarToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_reject']);
        $withoutReason->assertUnprocessable();
        self::assertArrayHasKey('reason', $withoutReason->json('error.errors'));

        $withReason = $this->withToken($registrarToken)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'registrar_reject',
            'reason' => 'Missing prerequisite documentation.',
        ]);
        $withReason->assertOk()->assertJsonPath('data.status', 'rejected');
        self::assertSame(
            'Missing prerequisite documentation.',
            AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->reason,
        );
    }

    public function test_void_transitions_a_pending_payment_enrollment_to_cancelled_and_requires_a_reason(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingPayment);
        $registrarToken = $this->tokenForNewStaff(UserRole::RegistrarHead, 'registrar.void@grc.test');

        $withoutReason = $this->withToken($registrarToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'void']);
        $withoutReason->assertUnprocessable();

        $response = $this->withToken($registrarToken)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'void',
            'reason' => 'Duplicate submission created in error.',
        ]);
        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
        self::assertSame(AuditAction::ENROLLMENT_VOIDED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_void_cannot_be_performed_on_an_enrolled_enrollment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::Enrolled);
        $registrarToken = $this->tokenForNewStaff(UserRole::RegistrarHead, 'registrar.voidwrongstate@grc.test');

        $response = $this->withToken($registrarToken)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'void',
            'reason' => 'A paid enrollment is a withdrawal, not a void.',
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame('enrolled', $enrollment->refresh()->status->value);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    /**
     * Submits one real section as the student (so a seat is genuinely taken)
     * and returns [student token, enrollment id, section].
     *
     * @return array{0: string, 1: int, 2: Section}
     */
    private function submitOneSection(string $email = 'student.enroll@grc.test'): array
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101', 3.0);
        $this->placeSubject($curriculum, $subject);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student);

        $id = (int) $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'sections' => [['section_id' => $section->id]],
        ])->assertCreated()->json('data.id');
        self::assertSame(1, $section->refresh()->enrolled_count);

        return [$token, $id, $section];
    }

    public function test_a_student_can_cancel_a_submitted_enrollment_and_the_seat_is_released_once(): void
    {
        [$token, $enrollmentId, $section] = $this->submitOneSection();

        $response = $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollmentId}", [
            'action' => 'student_cancel',
            'reason' => 'Picked the wrong section.',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
        self::assertSame(0, $section->refresh()->enrolled_count);
        $this->assertDatabaseHas('enrollment_subjects', ['enrollment_id' => $enrollmentId, 'status' => 'dropped']);
        $audit = AuditLog::query()->where('action', AuditAction::ENROLLMENT_CANCELLED_BY_STUDENT)->sole();
        self::assertSame('Picked the wrong section.', $audit->reason);
        self::assertSame([$section->id], $audit->after_values['released_section_ids']);

        // A repeat is refused and cannot release the seat a second time.
        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollmentId}", [
            'action' => 'student_cancel',
            'reason' => 'Again.',
        ])->assertUnprocessable();
        self::assertSame(0, $section->refresh()->enrolled_count);
    }

    public function test_a_student_can_enroll_again_after_cancelling(): void
    {
        [$token, $enrollmentId, $section] = $this->submitOneSection();

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollmentId}", [
            'action' => 'student_cancel',
            'reason' => 'Wrong section.',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $section->academic_term_id,
            'sections' => [['section_id' => $section->id]],
        ])->assertCreated();
        self::assertSame(1, $section->refresh()->enrolled_count);
    }

    public function test_a_student_cancellation_needs_a_reason(): void
    {
        [$token, $enrollmentId, $section] = $this->submitOneSection();

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'student_cancel'])
            ->assertUnprocessable();

        self::assertSame(1, $section->refresh()->enrolled_count);
    }

    public function test_a_student_cannot_cancel_once_the_registrar_has_approved(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingPayment);
        $token = $this->tokenFor($student);

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'student_cancel',
            'reason' => 'Too late.',
        ])->assertUnprocessable();

        self::assertSame('pending_payment', $enrollment->refresh()->status->value);
    }

    public function test_a_student_cannot_cancel_someone_elses_enrollment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $other = $this->makeStudentWithEmail($curriculum, 'other.cancel@grc.test', '2026-0009');
        $enrollment = $this->makeEnrollment($other, $term, EnrollmentStatus::PendingRegistrarApproval);
        $token = $this->tokenFor($student);

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'student_cancel',
            'reason' => 'Not mine.',
        ])->assertForbidden();

        self::assertSame('pending_registrar_approval', $enrollment->refresh()->status->value);
    }

    /**
     * An enrollment that already holds one seat, built with Eloquent so a
     * test can then act as a different user (chaining `withToken()` for two
     * users in one test silently keeps the first).
     *
     * @return array{0: Enrollment, 1: Section}
     */
    private function enrollmentHoldingASeat(EnrollmentStatus $status): array
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101', 3.0);
        $section = $this->makeSection($term, $subject);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, $status);
        EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
            'status' => EnrollmentSubjectStatus::Selected,
        ]);
        $section->increment('enrolled_count');

        return [$enrollment, $section];
    }

    public function test_registrar_staff_can_void_a_pending_enrollment_at_the_students_request_and_the_seat_is_released(): void
    {
        [$enrollment, $section] = $this->enrollmentHoldingASeat(EnrollmentStatus::PendingRegistrarApproval);
        $enrollmentId = $enrollment->id;
        $registrarToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.void@grc.test');

        $response = $this->withToken($registrarToken)->patchJson("/api/v1/enrollments/{$enrollmentId}", [
            'action' => 'void',
            'reason' => 'Student asked to change section.',
            'requested_by_student' => true,
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
        self::assertSame(0, $section->refresh()->enrolled_count);
        $audit = AuditLog::query()->where('action', AuditAction::ENROLLMENT_VOIDED)->sole();
        self::assertTrue($audit->after_values['requested_by_student']);
    }

    public function test_a_registrar_rejection_gives_the_seat_back(): void
    {
        [$enrollment, $section] = $this->enrollmentHoldingASeat(EnrollmentStatus::PendingRegistrarApproval);
        $enrollmentId = $enrollment->id;
        $registrarToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.reject.seat@grc.test');

        $this->withToken($registrarToken)->patchJson("/api/v1/enrollments/{$enrollmentId}", [
            'action' => 'registrar_reject',
            'reason' => 'Incomplete requirements.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        self::assertSame(0, $section->refresh()->enrolled_count);
    }

    public function test_a_registrar_can_reject_an_approved_enrollment_before_payment_and_release_its_seat_and_ticket(): void
    {
        [$enrollment, $section] = $this->enrollmentHoldingASeat(EnrollmentStatus::PendingPayment);
        $enrollmentId = $enrollment->id;
        $cycle = QueueCycle::create(['opened_on' => '2026-08-22', 'last_ticket_sequence' => 1, 'last_claimed_on' => '2026-08-22']);
        $ticket = QueueTicket::create([
            'enrollment_id' => $enrollmentId, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => '2026-08-22', 'status' => QueueTicketStatus::Waiting,
        ]);
        $registrarToken = $this->tokenForNewStaff(UserRole::RegistrarHead, 'registrar.head.reject.approved@grc.test');

        $this->withToken($registrarToken)->patchJson("/api/v1/enrollments/{$enrollmentId}", [
            'action' => 'registrar_reject',
            'reason' => 'Requirements were found incomplete after approval.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        self::assertSame(0, $section->refresh()->enrolled_count);
        self::assertSame(QueueTicketStatus::Cancelled, $ticket->refresh()->status);
        self::assertSame(
            'Requirements were found incomplete after approval.',
            AuditLog::query()->where('action', AuditAction::ENROLLMENT_REGISTRAR_REJECTED)->sole()->reason,
        );
    }

    public function test_a_registrar_cannot_reject_an_enrollment_that_is_already_enrolled(): void
    {
        $term = $this->makeTerm();
        $student = $this->makeStudent($this->makeCurriculum());
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::Enrolled);
        $registrarToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.staff.reject.enrolled@grc.test');

        $this->withToken($registrarToken)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'registrar_reject',
            'reason' => 'Too late.',
        ])->assertUnprocessable();

        self::assertSame('enrolled', $enrollment->refresh()->status->value);
    }

    public function test_a_non_registrar_staff_role_cannot_perform_registrar_approve(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
        $studentToken = $this->tokenFor($student);

        $response = $this->withToken($studentToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve']);

        $response->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        self::assertSame('pending_registrar_approval', $enrollment->refresh()->status->value);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_an_accounting_staff_role_cannot_void_an_enrollment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingPayment);
        $accountingToken = $this->tokenForNewStaff(UserRole::AccountingStaff, 'accounting.void@grc.test');

        $response = $this->withToken($accountingToken)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'void',
            'reason' => 'Not accounting\'s call.',
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        self::assertSame('pending_payment', $enrollment->refresh()->status->value);
    }

    private function tokenForProgramHead(string $email, ?CollegeCode $college): string
    {
        User::create([
            'name' => 'Program Head', 'email' => $email, 'college' => $college,
            'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_a_program_head_approves_an_irregular_enrollment_forward_to_the_registrar(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $curriculum->program->update(['college' => CollegeCode::Ccs]);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingProgramHeadApproval);
        $chairToken = $this->tokenForProgramHead('head.forward@grc.test', CollegeCode::Ccs);

        $response = $this->withToken($chairToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'program_head_approve']);

        $response->assertOk()->assertJsonPath('data.status', 'pending_registrar_approval');
        $enrollment->refresh();
        self::assertNotNull($enrollment->program_head_decided_at);
        self::assertNull($enrollment->registrar_decided_at);
        // Still no assessment: only the Registrar's approval creates one.
        $this->assertDatabaseCount('assessments', 0);
        self::assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::ENROLLMENT_PROGRAM_HEAD_APPROVED)->count(),
        );
    }

    public function test_a_program_head_approval_tells_the_registrar_the_enrollment_is_waiting(): void
    {
        $staff = $this->makeRegistrarUser(UserRole::RegistrarStaff, 'registrar.staff.forward@grc.test');
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $curriculum->program->update(['college' => CollegeCode::Ccs]);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingProgramHeadApproval);

        $this->withToken($this->tokenForProgramHead('head.notify.forward@grc.test', CollegeCode::Ccs))
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'program_head_approve'])
            ->assertOk();

        $notification = Notification::query()->where('user_id', $staff->id)->sole();
        self::assertSame(NotificationType::EnrollmentProgramHeadApproved, $notification->type);
        self::assertStringContainsString($student->student_number, $notification->message);
    }

    public function test_a_program_head_rejection_needs_a_reason_and_ends_the_enrollment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $curriculum->program->update(['college' => CollegeCode::Ccs]);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingProgramHeadApproval);
        $chairToken = $this->tokenForProgramHead('head.reject@grc.test', CollegeCode::Ccs);

        $this->withToken($chairToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'program_head_reject'])
            ->assertUnprocessable();

        $this->withToken($chairToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'program_head_reject', 'reason' => 'Schedule clash'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_a_program_head_cannot_decide_another_colleges_student(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $curriculum->program->update(['college' => CollegeCode::Ccs]);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingProgramHeadApproval);
        $otherChairToken = $this->tokenForProgramHead('head.other@grc.test', CollegeCode::Coe);

        $this->withToken($otherChairToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'program_head_approve'])
            ->assertForbidden();

        self::assertSame('pending_program_head_approval', $enrollment->refresh()->status->value);
    }

    public function test_a_program_head_without_an_assigned_college_cannot_decide(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingProgramHeadApproval);
        $chairToken = $this->tokenForProgramHead('head.nocollege@grc.test', null);

        $this->withToken($chairToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'program_head_approve'])
            ->assertForbidden();
    }

    public function test_the_registrar_cannot_skip_the_program_head_stage(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingProgramHeadApproval);
        $registrarToken = $this->tokenForNewStaff(UserRole::RegistrarStaff, 'registrar.skip@grc.test');

        $this->withToken($registrarToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve'])
            ->assertUnprocessable();

        self::assertSame('pending_program_head_approval', $enrollment->refresh()->status->value);
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_authorized_roles_can_preview_cor_without_persisting_document(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingPayment);

        $acctToken = $this->tokenForNewStaff(UserRole::AccountingStaff, 'acct.cor@grc.test');
        $studentToken = $this->tokenFor($student);

        $response = $this->withToken($acctToken)
            ->getJson("/api/v1/enrollments/{$enrollment->id}/cor-preview");

        $response->assertOk();
        $response->assertJsonPath('data.watermark', 'Preview — official COR is issued after payment confirmation');
        $response->assertJsonStructure(['data' => ['snapshot', 'watermark']]);

        $this->assertDatabaseCount('enrollment_documents', 0);

        // One Sanctum actor per request chain: switch to the Student.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withToken($studentToken)
            ->getJson("/api/v1/enrollments/{$enrollment->id}/cor-preview")
            ->assertForbidden();
    }
}
