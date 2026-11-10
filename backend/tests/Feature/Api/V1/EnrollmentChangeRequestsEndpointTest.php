<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentChangeRequestStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentChangeRequest;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnrollmentChangeRequestsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTermWithOpenWindow(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
            'enrollment_closes_at' => now()->subDay(),
            'add_drop_deadline_at' => now()->addDays(7),
        ]);
    }

    private function makeTermWithClosedWindow(): AcademicTerm
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

    private function makeStudent(Curriculum $curriculum, string $email = 'student.change@grc.test', string $studentNumber = '2026-0001'): StudentProfile
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

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
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

    private function makeSection(AcademicTerm $term, string $subjectCode, array $overrides = []): Section
    {
        $subject = Subject::create(['code' => $subjectCode, 'title' => $subjectCode.' Title', 'units' => 3.0, 'status' => SubjectStatus::Active]);

        return Section::create(array_merge([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'capacity' => 40, 'enrolled_count' => 0, 'status' => SectionStatus::Published,
        ], $overrides));
    }

    /**
     * Doc 12: an add (or a swap to another subject) is only accepted for a
     * subject that is in the student's own curriculum, so tests that expect a
     * request to be ACCEPTED place the target section's subject there first.
     */
    private function placeInCurriculum(Curriculum $curriculum, Section $section): void
    {
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $section->subject_id,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
        ]);
    }

    private function makeEnrolledEnrollment(StudentProfile $student, AcademicTerm $term): Enrollment
    {
        return Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled, 'total_units' => 3, 'submitted_at' => now(),
            'registrar_decided_at' => now(), 'payment_confirmed_at' => now(), 'enrolled_at' => now(),
        ]);
    }

    private function occupySeat(Enrollment $enrollment, Section $section): EnrollmentSubject
    {
        $section->increment('enrolled_count');

        return EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id, 'section_id' => $section->id,
            'status' => EnrollmentSubjectStatus::Enrolled,
        ]);
    }

    private function makeChangeRequest(Enrollment $enrollment, array $overrides = []): EnrollmentChangeRequest
    {
        $subjectId = $overrides['subject_id'] ?? Subject::create([
            'code' => 'ELEC'.random_int(100, 999), 'title' => 'Placeholder Elective', 'units' => 3.0, 'status' => SubjectStatus::Active,
        ])->id;

        return EnrollmentChangeRequest::create(array_merge([
            'enrollment_id' => $enrollment->id,
            'type' => 'add',
            'subject_id' => $subjectId,
            'reason' => 'Schedule conflict.',
            'status' => EnrollmentChangeRequestStatus::Pending,
        ], $overrides));
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->postJson('/api/v1/enrollments/1/change-requests', [])->assertUnauthorized();
        $this->getJson('/api/v1/enrollment-change-requests')->assertUnauthorized();
    }

    public function test_a_student_can_request_to_add_a_subject_during_the_open_window(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $toSection = $this->makeSection($term, 'CS102');
        $this->placeInCurriculum($curriculum, $toSection);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'add',
            'to_section_id' => $toSection->id,
            'reason' => 'Need this subject to graduate on time.',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.request_type', 'add');
        self::assertSame(AuditAction::ENROLLMENT_CHANGE_REQUEST_CREATED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_adding_a_subject_from_another_curriculum_is_rejected(): void
    {
        // Doc 12: "hindi lalabas yung mga subject from other curriculum".
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $otherProgramSection = $this->makeSection($term, 'ACC101');
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'add',
            'to_section_id' => $otherProgramSection->id,
            'reason' => 'Trying a subject outside my curriculum.',
        ]);

        $response->assertUnprocessable();
        self::assertSame('That subject is not part of your curriculum.', $response->json('error.errors.to_section_id.0'));
        $this->assertDatabaseCount('enrollment_change_requests', 0);
    }

    public function test_swapping_to_a_subject_from_another_curriculum_is_rejected(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $held = $this->makeSection($term, 'CS101');
        $this->placeInCurriculum($curriculum, $held);
        $this->occupySeat($enrollment, $held);
        $otherProgramSection = $this->makeSection($term, 'ACC101');
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'change_section',
            'from_section_id' => $held->id,
            'to_section_id' => $otherProgramSection->id,
            'reason' => 'Swap to a subject outside my curriculum.',
        ]);

        $response->assertUnprocessable();
        self::assertSame('That subject is not part of your curriculum.', $response->json('error.errors.to_section_id.0'));
    }

    public function test_a_sibling_subject_row_with_the_same_code_and_units_counts_as_the_curriculum_subject(): void
    {
        // General-education subjects exist once per college with the same code
        // and units; the pool treats them as one subject and so must this check.
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $placed = Subject::create(['code' => 'GE101', 'college' => 'ccs', 'title' => 'Purposive Communication', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $placed->id,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
        ]);
        $sibling = Subject::create(['code' => 'GE101', 'college' => 'coe', 'title' => 'Purposive Communication', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        $siblingSection = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $sibling->id, 'section_code' => 'A',
            'capacity' => 40, 'enrolled_count' => 0, 'status' => SectionStatus::Published,
        ]);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'add',
            'to_section_id' => $siblingSection->id,
            'reason' => 'The other college offers a better time.',
        ]);

        $response->assertCreated();
    }

    public function test_a_student_can_request_to_drop_a_currently_held_subject(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $section = $this->makeSection($term, 'CS101');
        $this->occupySeat($enrollment, $section);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'drop',
            'from_section_id' => $section->id,
            'reason' => 'Overloaded this term.',
        ]);

        $response->assertCreated()->assertJsonPath('data.request_type', 'drop');
    }

    public function test_a_student_can_request_a_section_change_within_the_same_subject(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Programming 1', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        $fromSection = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'capacity' => 40, 'enrolled_count' => 1, 'status' => SectionStatus::Published,
        ]);
        $this->occupySeat($enrollment, $fromSection);
        $toSection = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'B',
            'capacity' => 40, 'enrolled_count' => 0, 'status' => SectionStatus::Published,
            'schedule_days' => 'T', 'starts_at_time' => '13:00:00', 'ends_at_time' => '14:00:00',
        ]);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'change_section',
            'from_section_id' => $fromSection->id,
            'to_section_id' => $toSection->id,
            'reason' => 'Time conflict with another subject.',
        ]);

        $response->assertCreated()->assertJsonPath('data.request_type', 'change_section');
    }

    public function test_a_request_is_rejected_while_the_add_drop_window_is_closed(): void
    {
        $term = $this->makeTermWithClosedWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $toSection = $this->makeSection($term, 'CS102');
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'add',
            'to_section_id' => $toSection->id,
            'reason' => 'Too early.',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('enrollment_change_requests', 0);
    }

    public function test_a_request_is_rejected_for_a_non_enrolled_enrollment(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $enrollment->update(['status' => EnrollmentStatus::PendingPayment]);
        $toSection = $this->makeSection($term, 'CS102');
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'add',
            'to_section_id' => $toSection->id,
            'reason' => 'Not yet enrolled.',
        ]);

        $response->assertUnprocessable();
    }

    public function test_dropping_a_section_not_currently_held_is_rejected(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $notHeld = $this->makeSection($term, 'CS103');
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'drop',
            'from_section_id' => $notHeld->id,
            'reason' => 'Not actually mine.',
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('from_section_id', $response->json('error.errors'));
    }

    public function test_adding_a_full_section_is_rejected(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $fullSection = $this->makeSection($term, 'CS104', ['capacity' => 1, 'enrolled_count' => 1]);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'add',
            'to_section_id' => $fullSection->id,
            'reason' => 'Wants in anyway.',
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('to_section_id', $response->json('error.errors'));
    }

    public function test_a_second_pending_request_for_the_same_subject_is_rejected(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $subject = Subject::create(['code' => 'CS105', 'title' => 'Data Structures', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        $sectionA = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'capacity' => 40, 'status' => SectionStatus::Published,
        ]);
        $sectionB = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'B',
            'capacity' => 40, 'status' => SectionStatus::Published,
            'schedule_days' => 'T', 'starts_at_time' => '13:00:00', 'ends_at_time' => '14:00:00',
        ]);
        $this->placeInCurriculum($curriculum, $sectionA);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'add', 'to_section_id' => $sectionA->id, 'reason' => 'First request.',
        ])->assertCreated();

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'add', 'to_section_id' => $sectionB->id, 'reason' => 'Second request, same subject.',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('enrollment_change_requests', 1);
    }

    public function test_a_student_cannot_request_a_change_for_another_students_enrollment(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $owner = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($owner, $term);
        $other = $this->makeStudent($curriculum, 'other.change@grc.test', '2026-0002');
        $toSection = $this->makeSection($term, 'CS106');
        $this->placeInCurriculum($curriculum, $toSection);
        $token = $this->tokenFor($other->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/change-requests", [
            'type' => 'add', 'to_section_id' => $toSection->id, 'reason' => 'Not my enrollment.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('enrollment_change_requests', 0);
    }

    public function test_registrar_head_approves_an_add_request_and_occupies_a_seat(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $toSection = $this->makeSection($term, 'CS107');
        $request = $this->makeChangeRequest($enrollment, [
            'type' => 'add', 'subject_id' => $toSection->subject_id, 'to_section_id' => $toSection->id,
        ]);
        $registrarHeadToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar-head.approve-add@grc.test');

        $response = $this->withToken($registrarHeadToken)->patchJson(
            "/api/v1/enrollment-change-requests/{$request->id}",
            ['action' => 'approve'],
        );

        $response->assertOk()->assertJsonPath('data.status', 'approved');
        self::assertSame(1, $toSection->refresh()->enrolled_count);
        self::assertTrue(
            EnrollmentSubject::query()->where('enrollment_id', $enrollment->id)->where('section_id', $toSection->id)->exists(),
        );
        self::assertSame(
            AuditAction::ENROLLMENT_CHANGE_REQUEST_APPROVED,
            AuditLog::query()->where('auditable_type', 'enrollment_change_request')->sole()->action,
        );
        self::assertSame(NotificationType::EnrollmentChangeRequestApproved, Notification::query()->sole()->type);
    }

    public function test_registrar_head_approves_a_drop_request_and_releases_the_seat(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $section = $this->makeSection($term, 'CS108');
        $enrollmentSubject = $this->occupySeat($enrollment, $section);
        $request = $this->makeChangeRequest($enrollment, [
            'type' => 'drop', 'subject_id' => $section->subject_id, 'from_section_id' => $section->id,
        ]);
        $registrarHeadToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar-head.approve-drop@grc.test');

        $response = $this->withToken($registrarHeadToken)->patchJson(
            "/api/v1/enrollment-change-requests/{$request->id}",
            ['action' => 'approve'],
        );

        $response->assertOk();
        self::assertSame(0, $section->refresh()->enrolled_count);
        self::assertSame('dropped', $enrollmentSubject->refresh()->status->value);
        self::assertSame('enrolled', $enrollment->refresh()->status->value);
    }

    public function test_registrar_head_approves_a_change_section_request_atomically(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $subject = Subject::create(['code' => 'CS109', 'title' => 'Algorithms', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        $fromSection = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'capacity' => 40, 'enrolled_count' => 1, 'status' => SectionStatus::Published,
        ]);
        $enrollmentSubject = $this->occupySeat($enrollment, $fromSection);
        $fromSection->update(['enrolled_count' => 1]);
        $toSection = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'B',
            'capacity' => 40, 'enrolled_count' => 0, 'status' => SectionStatus::Published,
            'schedule_days' => 'T', 'starts_at_time' => '13:00:00', 'ends_at_time' => '14:00:00',
        ]);
        $request = $this->makeChangeRequest($enrollment, [
            'type' => 'change_section', 'subject_id' => $subject->id,
            'from_section_id' => $fromSection->id, 'to_section_id' => $toSection->id,
        ]);
        $registrarHeadToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar-head.approve-change@grc.test');

        $response = $this->withToken($registrarHeadToken)->patchJson(
            "/api/v1/enrollment-change-requests/{$request->id}",
            ['action' => 'approve'],
        );

        $response->assertOk();
        self::assertSame(0, $fromSection->refresh()->enrolled_count);
        self::assertSame(1, $toSection->refresh()->enrolled_count);
        self::assertSame('dropped', $enrollmentSubject->refresh()->status->value);
        self::assertTrue(
            EnrollmentSubject::query()->where('enrollment_id', $enrollment->id)->where('section_id', $toSection->id)->exists(),
        );
    }

    public function test_approval_is_rejected_when_the_target_section_loses_its_last_seat(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $toSection = $this->makeSection($term, 'CS110', ['capacity' => 1, 'enrolled_count' => 0]);
        $request = $this->makeChangeRequest($enrollment, [
            'type' => 'add', 'subject_id' => $toSection->subject_id, 'to_section_id' => $toSection->id,
        ]);
        // Simulates the seat filling up between submission and approval.
        $toSection->update(['enrolled_count' => 1]);
        $registrarHeadToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar-head.approve-race@grc.test');

        $response = $this->withToken($registrarHeadToken)->patchJson(
            "/api/v1/enrollment-change-requests/{$request->id}",
            ['action' => 'approve'],
        );

        $response->assertUnprocessable();
        self::assertSame('pending', $request->refresh()->status->value);
        $this->assertDatabaseCount('enrollment_subjects', 0);
    }

    public function test_registrar_head_rejects_a_request_requiring_a_reason(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $toSection = $this->makeSection($term, 'CS111');
        $request = $this->makeChangeRequest($enrollment, [
            'type' => 'add', 'subject_id' => $toSection->subject_id, 'to_section_id' => $toSection->id,
        ]);
        $registrarHeadToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar-head.reject@grc.test');

        $this->withToken($registrarHeadToken)->patchJson(
            "/api/v1/enrollment-change-requests/{$request->id}",
            ['action' => 'reject'],
        )->assertUnprocessable();

        $response = $this->withToken($registrarHeadToken)->patchJson(
            "/api/v1/enrollment-change-requests/{$request->id}",
            ['action' => 'reject', 'reason' => 'Would exceed unit cap.'],
        );

        $response->assertOk()->assertJsonPath('data.status', 'rejected');
        $response->assertJsonPath('data.decision_reason', 'Would exceed unit cap.');
        self::assertSame(0, $toSection->refresh()->enrolled_count);
    }

    public function test_registrar_staff_cannot_decide_a_change_request(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrolledEnrollment($student, $term);
        $toSection = $this->makeSection($term, 'CS112');
        $request = $this->makeChangeRequest($enrollment, [
            'type' => 'add', 'subject_id' => $toSection->subject_id, 'to_section_id' => $toSection->id,
        ]);
        $registrarStaffToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar-staff.forbidden@grc.test');

        $response = $this->withToken($registrarStaffToken)->patchJson(
            "/api/v1/enrollment-change-requests/{$request->id}",
            ['action' => 'approve'],
        );

        $response->assertForbidden();
        self::assertSame('pending', $request->refresh()->status->value);
    }

    public function test_a_student_sees_only_their_own_requests(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum);
        $enrollmentA = $this->makeEnrolledEnrollment($studentA, $term);
        $this->makeChangeRequest($enrollmentA);

        $studentB = $this->makeStudent($curriculum, 'student.b.change@grc.test', '2026-0003');
        $enrollmentB = $this->makeEnrolledEnrollment($studentB, $term);
        $this->makeChangeRequest($enrollmentB);

        $tokenA = $this->tokenFor($studentA->user);
        $response = $this->withToken($tokenA)->getJson('/api/v1/enrollment-change-requests');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_registrar_staff_can_view_every_request_but_not_decide(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum);
        $enrollmentA = $this->makeEnrolledEnrollment($studentA, $term);
        $this->makeChangeRequest($enrollmentA);

        $studentB = $this->makeStudent($curriculum, 'student.b.change2@grc.test', '2026-0003');
        $enrollmentB = $this->makeEnrolledEnrollment($studentB, $term);
        $this->makeChangeRequest($enrollmentB);

        $registrarStaffToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar-staff.viewall-change@grc.test');
        $response = $this->withToken($registrarStaffToken)->getJson('/api/v1/enrollment-change-requests');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_registrar_head_sees_every_request(): void
    {
        $term = $this->makeTermWithOpenWindow();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum);
        $enrollmentA = $this->makeEnrolledEnrollment($studentA, $term);
        $this->makeChangeRequest($enrollmentA);

        $studentB = $this->makeStudent($curriculum, 'student.b.change3@grc.test', '2026-0003');
        $enrollmentB = $this->makeEnrolledEnrollment($studentB, $term);
        $this->makeChangeRequest($enrollmentB);

        $registrarHeadToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar-head.viewall-change@grc.test');
        $response = $this->withToken($registrarHeadToken)->getJson('/api/v1/enrollment-change-requests');

        $response->assertOk()->assertJsonCount(2, 'data');
    }
}
