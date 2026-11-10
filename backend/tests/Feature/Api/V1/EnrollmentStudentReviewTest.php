<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentRevision;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR 0040: when the Program Chair changes an irregular student's subjects, the
 * enrollment goes back to the student with the Chair's note. Accepting sends it
 * straight to the Registrar; declining (with a reason) returns it to the Chair.
 * Several people act in one test, so the helper clears Sanctum's cached user
 * before each switch (nothing about the other actors is faked).
 */
final class EnrollmentStudentReviewTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private int $sequence = 0;

    private AcademicTerm $term;

    private Curriculum $curriculum;

    private function makeUser(UserRole $role, ?CollegeCode $college = null): User
    {
        $this->sequence++;

        return User::create([
            'name' => 'Test '.$role->value.' '.$this->sequence, 'email' => "{$role->value}.review{$this->sequence}@grc.test",
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            'college' => $college,
        ]);
    }

    private function as(User $user): static
    {
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
        $this->app->make('auth')->forgetGuards();

        return $this->withToken($token);
    }

    private function makeSubject(string $code, float $units = 3.0): Subject
    {
        $subject = Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => $units, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create([
            'curriculum_id' => $this->curriculum->id, 'subject_id' => $subject->id,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
        ]);

        return $subject;
    }

    private function makeSection(Subject $subject, array $overrides = []): Section
    {
        return Section::create(array_merge([
            'academic_term_id' => $this->term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'capacity' => 40, 'status' => SectionStatus::Published,
        ], $overrides));
    }

    private function makeStudent(): StudentProfile
    {
        $user = $this->makeUser(UserRole::Student);

        return StudentProfile::create([
            'user_id' => $user->id, 'student_number' => sprintf('2026-%04d', $this->sequence),
            'program_id' => $this->curriculum->program_id, 'curriculum_id' => $this->curriculum->id,
            'year_level' => 1, 'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good, 'enrollment_category' => 'irregular',
        ]);
    }

    /**
     * @param  list<Section>  $sections
     */
    private function makeEnrollment(StudentProfile $student, array $sections): Enrollment
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $this->term->id,
            'status' => EnrollmentStatus::PendingProgramHeadApproval,
            'total_units' => array_sum(array_map(fn (Section $s): float => (float) $s->subject->units, $sections)),
            'submitted_at' => now(),
        ]);

        foreach ($sections as $section) {
            EnrollmentSubject::create(['enrollment_id' => $enrollment->id, 'section_id' => $section->id, 'status' => EnrollmentSubjectStatus::Selected]);
            $section->increment('enrolled_count');
        }

        return $enrollment;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BSCS Program', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $this->curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    /**
     * An irregular student's enrollment the Chair has just changed (CS101 swapped for CS102).
     *
     * @return array{student: StudentProfile, enrollment: Enrollment, chair: User, sectionA: Section, sectionB: Section}
     */
    private function enrollmentSentToTheStudent(): array
    {
        $sectionA = $this->makeSection($this->makeSubject('CS101'));
        $sectionB = $this->makeSection($this->makeSubject('CS102'));
        $student = $this->makeStudent();
        $enrollment = $this->makeEnrollment($student, [$sectionA]);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->as($chair)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$sectionB->id],
            'note' => 'CS101 clashes with your other class; CS102 is open and counts the same.',
        ])->assertOk();

        return compact('student', 'enrollment', 'chair', 'sectionA', 'sectionB');
    }

    public function test_a_revision_needs_a_note_and_an_actual_change(): void
    {
        $sectionA = $this->makeSection($this->makeSubject('CS101'));
        $sectionB = $this->makeSection($this->makeSubject('CS102'));
        $enrollment = $this->makeEnrollment($this->makeStudent(), [$sectionA]);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->as($chair)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", ['section_ids' => [$sectionB->id]])
            ->assertUnprocessable();
        $this->as($chair)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", ['section_ids' => [$sectionB->id], 'note' => '   '])
            ->assertUnprocessable();
        // Same subjects as before: nothing to ask the student about.
        $this->as($chair)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", ['section_ids' => [$sectionA->id], 'note' => 'No change.'])
            ->assertUnprocessable();

        self::assertSame(EnrollmentStatus::PendingProgramHeadApproval, $enrollment->refresh()->status);
        self::assertSame(0, EnrollmentRevision::query()->count());
    }

    public function test_changing_the_subjects_sends_the_enrollment_back_to_the_student_with_the_note(): void
    {
        ['student' => $student, 'enrollment' => $enrollment, 'sectionA' => $sectionA, 'sectionB' => $sectionB] = $this->enrollmentSentToTheStudent();

        $enrollment->refresh();
        self::assertSame(EnrollmentStatus::PendingStudentReview, $enrollment->status);
        // The seats follow the Chair's version while the student decides.
        self::assertSame(0, $sectionA->refresh()->enrolled_count);
        self::assertSame(1, $sectionB->refresh()->enrolled_count);

        $revision = EnrollmentRevision::query()->sole();
        self::assertSame(EnrollmentRevision::STATUS_PENDING, $revision->status);
        self::assertSame('CS102', $revision->added_subjects[0]['subject_code']);
        self::assertSame('CS101', $revision->removed_subjects[0]['subject_code']);

        $notice = Notification::query()->where('user_id', $student->user_id)->sole();
        self::assertSame(NotificationType::EnrollmentProgramHeadSubjectsRevised, $notice->type);
        self::assertStringContainsString('CS101 clashes with your other class', $notice->message);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::ENROLLMENT_PROGRAM_HEAD_SUBJECTS_REVISED, 'reason' => 'CS101 clashes with your other class; CS102 is open and counts the same.']);

        // The student sees the note and what changed in their own enrollment list.
        $this->as($student->user)->getJson('/api/v1/enrollments')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'pending_student_review')
            ->assertJsonPath('data.0.revisions.0.note', 'CS101 clashes with your other class; CS102 is open and counts the same.')
            ->assertJsonPath('data.0.revisions.0.added_subjects.0.subject_code', 'CS102');
    }

    public function test_the_chair_cannot_decide_while_the_student_is_reviewing(): void
    {
        ['enrollment' => $enrollment, 'chair' => $chair] = $this->enrollmentSentToTheStudent();

        $this->as($chair)->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'program_head_approve'])->assertUnprocessable();
        $this->as($chair)->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'program_head_reject', 'reason' => 'No.'])->assertUnprocessable();
        self::assertSame(EnrollmentStatus::PendingStudentReview, $enrollment->refresh()->status);
    }

    public function test_accepting_the_changes_goes_straight_to_the_registrar(): void
    {
        ['student' => $student, 'enrollment' => $enrollment, 'chair' => $chair] = $this->enrollmentSentToTheStudent();
        $registrar = $this->makeUser(UserRole::RegistrarStaff);

        $this->as($student->user)->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'student_accept_revision'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_registrar_approval')
            ->assertJsonPath('data.revisions.0.status', 'accepted');

        $enrollment->refresh();
        self::assertSame(EnrollmentStatus::PendingRegistrarApproval, $enrollment->status);
        self::assertNotNull($enrollment->program_head_decided_at);
        self::assertNull($enrollment->registrar_decided_at);
        self::assertNotNull(EnrollmentRevision::query()->sole()->responded_at);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::ENROLLMENT_STUDENT_ACCEPTED_REVISION, 'auditable_id' => $enrollment->id]);
        // The Registrar's queue now holds it, and the Chair is told it was accepted.
        $this->assertDatabaseHas('notifications', ['user_id' => $registrar->id, 'type' => NotificationType::EnrollmentProgramHeadApproved->value]);
        $this->assertDatabaseHas('notifications', ['user_id' => $chair->id, 'type' => NotificationType::EnrollmentRevisionAccepted->value]);
    }

    public function test_a_transferee_who_has_not_paid_goes_to_the_payment_hold_not_the_registrar_when_accepting_the_changes(): void
    {
        ['student' => $student, 'enrollment' => $enrollment] = $this->enrollmentSentToTheStudent();
        $student->update(['student_type' => StudentType::Transferee]);
        $registrar = $this->makeUser(UserRole::RegistrarStaff);

        $this->as($student->user)->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'student_accept_revision'])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_first_payment');

        self::assertSame(EnrollmentStatus::AwaitingFirstPayment, $enrollment->refresh()->status);
        self::assertNotNull($enrollment->program_head_decided_at);
        // Nobody at the Registrar is told yet: the enrollment has not been paid.
        $this->assertDatabaseMissing('notifications', ['user_id' => $registrar->id, 'type' => NotificationType::EnrollmentProgramHeadApproved->value]);
    }

    public function test_declining_needs_a_reason_and_returns_it_to_the_chair_with_that_reason(): void
    {
        ['student' => $student, 'enrollment' => $enrollment, 'chair' => $chair] = $this->enrollmentSentToTheStudent();

        $this->as($student->user)->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'student_decline_revision'])->assertUnprocessable();
        self::assertSame(EnrollmentStatus::PendingStudentReview, $enrollment->refresh()->status);

        $this->as($student->user)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'student_decline_revision', 'reason' => 'I work on Tuesdays, CS102 meets then.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending_program_head_approval')
            ->assertJsonPath('data.revisions.0.status', 'declined')
            ->assertJsonPath('data.revisions.0.student_reason', 'I work on Tuesdays, CS102 meets then.');

        $enrollment->refresh();
        self::assertSame(EnrollmentStatus::PendingProgramHeadApproval, $enrollment->status);
        self::assertNull($enrollment->program_head_decided_at);
        $notice = Notification::query()->where('user_id', $chair->id)->where('type', NotificationType::EnrollmentRevisionDeclined->value)->sole();
        self::assertStringContainsString('I work on Tuesdays', $notice->message);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::ENROLLMENT_STUDENT_DECLINED_REVISION, 'reason' => 'I work on Tuesdays, CS102 meets then.']);
    }

    public function test_after_a_decline_the_chair_can_propose_again_and_both_rounds_are_kept(): void
    {
        ['student' => $student, 'enrollment' => $enrollment, 'chair' => $chair, 'sectionB' => $sectionB] = $this->enrollmentSentToTheStudent();
        $sectionC = $this->makeSection($this->makeSubject('CS103'));
        $this->as($student->user)->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'student_decline_revision', 'reason' => 'Tuesday clash.'])->assertOk();

        $this->as($chair)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$sectionC->id], 'note' => 'CS103 meets Thursday instead.',
        ])->assertOk()->assertJsonPath('data.status', 'pending_student_review')->assertJsonCount(2, 'data.revisions');

        self::assertSame(['declined', 'pending'], EnrollmentRevision::query()->orderBy('id')->pluck('status')->all());
        self::assertSame(0, $sectionB->refresh()->enrolled_count);
    }

    public function test_only_the_owning_student_can_answer(): void
    {
        ['enrollment' => $enrollment, 'chair' => $chair] = $this->enrollmentSentToTheStudent();
        $stranger = $this->makeStudent();

        $this->as($stranger->user)->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'student_accept_revision'])->assertForbidden();
        $this->as($chair)->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'student_accept_revision'])->assertForbidden();
        $this->as($this->makeUser(UserRole::RegistrarHead))->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'student_decline_revision', 'reason' => 'x'])->assertForbidden();
        self::assertSame(EnrollmentStatus::PendingStudentReview, $enrollment->refresh()->status);
    }

    public function test_a_student_can_only_answer_while_the_chair_is_waiting_on_them(): void
    {
        $sectionA = $this->makeSection($this->makeSubject('CS101'));
        $student = $this->makeStudent();
        $enrollment = $this->makeEnrollment($student, [$sectionA]);

        $this->as($student->user)->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'student_accept_revision'])->assertUnprocessable();
        self::assertSame(EnrollmentStatus::PendingProgramHeadApproval, $enrollment->refresh()->status);
    }

    public function test_the_student_can_still_cancel_while_reviewing_and_the_seats_come_back(): void
    {
        ['student' => $student, 'enrollment' => $enrollment, 'sectionB' => $sectionB] = $this->enrollmentSentToTheStudent();

        $this->as($student->user)->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'student_cancel', 'reason' => 'Changed my mind.'])->assertOk();

        self::assertSame(EnrollmentStatus::Cancelled, $enrollment->refresh()->status);
        self::assertSame(0, $sectionB->refresh()->enrolled_count);
    }

    public function test_a_load_that_needs_overload_approval_must_be_acknowledged_before_it_is_sent(): void
    {
        config(['enrollment.max_regular_units' => 3, 'enrollment.overload_max_units' => 9]);
        $sectionA = $this->makeSection($this->makeSubject('CS101', 3.0));
        $sectionB = $this->makeSection($this->makeSubject('CS102', 3.0));
        $enrollment = $this->makeEnrollment($this->makeStudent(), [$sectionA]);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $payload = ['section_ids' => [$sectionA->id, $sectionB->id], 'note' => 'Adding CS102 so you finish on time.'];

        $this->as($chair)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", $payload)->assertUnprocessable();
        self::assertSame(EnrollmentStatus::PendingProgramHeadApproval, $enrollment->refresh()->status);
        self::assertSame(0, EnrollmentRevision::query()->count());

        $this->as($chair)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [...$payload, 'overload_acknowledged' => true])
            ->assertOk()->assertJsonPath('data.status', 'pending_student_review')->assertJsonPath('data.requires_overload_approval', true);
    }
}
