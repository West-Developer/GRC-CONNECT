<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentRevision;
use App\Models\EnrollmentSubject;
use App\Models\EnrollmentSubjectWaiver;
use App\Models\Notification;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectPrerequisite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Program Head adding/removing whole subjects from a student's proposed
 * schedule while it sits at their own review stage (owner-requested,
 * 2026-09-28), plus the optional comment a Program Head may leave alongside
 * their approve/reject decision on the same route these tests exercise via
 * `PATCH /enrollments/{enrollment}`.
 */
final class ReviseEnrollmentSubjectsEndpointTest extends TestCase
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
        $program = Program::create(['code' => 'BSCS', 'name' => 'BSCS Program', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeSubject(string $code, float $units = 3.0): Subject
    {
        return Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => $units, 'status' => SubjectStatus::Active]);
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
        foreach (Subject::query()->get() as $subject) {
            CurriculumSubject::create([
                'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
                'year_level' => 1, 'semester' => '1st', 'is_required' => true,
            ]);
        }
        $user = User::create([
            'name' => 'Test Student', 'email' => 'student.revise@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2026-0099',
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    /**
     * @param  list<Section>  $sections
     */
    private function makeEnrollment(StudentProfile $student, AcademicTerm $term, array $sections): Enrollment
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingProgramHeadApproval,
            'total_units' => array_sum(array_map(fn (Section $s): float => (float) $s->subject->units, $sections)),
            'submitted_at' => now(),
        ]);

        foreach ($sections as $section) {
            EnrollmentSubject::create([
                'enrollment_id' => $enrollment->id,
                'section_id' => $section->id,
                'status' => EnrollmentSubjectStatus::Selected,
            ]);
            $section->increment('enrolled_count');
        }

        return $enrollment;
    }

    private function tokenForProgramHead(string $email, ?CollegeCode $college = CollegeCode::Ccs): string
    {
        User::create([
            'name' => 'Program Head', 'email' => $email, 'college' => $college,
            'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_a_revision_cannot_add_another_section_of_the_same_subject(): void
    {
        $term = $this->makeTerm();
        $student = $this->makeStudent($this->makeCurriculum());
        $subject = $this->makeSubject('CS101');
        $first = $this->makeSection($term, $subject);
        $second = $this->makeSection($term, $subject, ['section_code' => 'B']);
        $enrollment = $this->makeEnrollment($student, $term, [$first]);
        $token = $this->tokenForProgramHead('head.duplicate@grc.test');

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$first->id, $second->id], 'note' => 'Add another section.',
        ])->assertUnprocessable();

        self::assertSame(0, $second->refresh()->enrolled_count);
        self::assertSame(EnrollmentStatus::PendingProgramHeadApproval, $enrollment->refresh()->status);
    }

    public function test_a_revision_cannot_duplicate_an_equivalent_course_from_another_college(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $own = $this->makeSubject('RIZAL');
        $own->update(['college' => 'ccs']);
        $other = $this->makeSubject('RIZAL');
        $other->update(['college' => 'coe']);
        $first = $this->makeSection($term, $own);
        $second = $this->makeSection($term, $other, ['section_code' => 'B']);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$first]);
        $token = $this->tokenForProgramHead('head.equivalent@grc.test');
        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$first->id, $second->id], 'note' => 'Add equivalent course.',
        ])->assertUnprocessable();
        self::assertSame(0, $second->refresh()->enrolled_count);
        self::assertSame(3.0, $enrollment->refresh()->total_units);
    }

    public function test_a_revision_cannot_add_a_conflicting_section(): void
    {
        $term = $this->makeTerm();
        $student = $this->makeStudent($this->makeCurriculum());
        $first = $this->makeSection($term, $this->makeSubject('CS101'), ['schedule_days' => 'MW', 'starts_at_time' => '08:00', 'ends_at_time' => '09:30']);
        $second = $this->makeSection($term, $this->makeSubject('CS102'), ['schedule_days' => 'M', 'starts_at_time' => '09:00', 'ends_at_time' => '10:00']);
        $enrollment = $this->makeEnrollment($student, $term, [$first]);
        $token = $this->tokenForProgramHead('head.conflict@grc.test');

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$first->id, $second->id], 'note' => 'Add the section.',
        ])->assertUnprocessable();

        self::assertSame(0, $second->refresh()->enrolled_count);
        self::assertSame(EnrollmentStatus::PendingProgramHeadApproval, $enrollment->refresh()->status);
    }

    public function test_a_revision_requires_the_matching_lecture_and_lab(): void
    {
        $term = $this->makeTerm();
        $student = $this->makeStudent($this->makeCurriculum());
        $lecture = $this->makeSubject('CS101');
        $lab = $this->makeSubject('CS101L');
        $lecture->update(['paired_subject_id' => $lab->id]);
        $existing = $this->makeSection($term, $this->makeSubject('CS102'));
        $lectureSection = $this->makeSection($term, $lecture);
        $enrollment = $this->makeEnrollment($student, $term, [$existing]);
        $token = $this->tokenForProgramHead('head.pair@grc.test');

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$existing->id, $lectureSection->id], 'note' => 'Add lecture.',
        ])->assertUnprocessable();

        self::assertSame(0, $lectureSection->refresh()->enrolled_count);
        self::assertSame(EnrollmentStatus::PendingProgramHeadApproval, $enrollment->refresh()->status);
    }

    public function test_a_revision_cannot_add_a_subject_outside_the_students_curriculum(): void
    {
        $term = $this->makeTerm();
        $student = $this->makeStudent($this->makeCurriculum());
        $existing = $this->makeSection($term, $this->makeSubject('CS101'));
        $outside = $this->makeSection($term, $this->makeSubject('OUTSIDE'));
        $enrollment = $this->makeEnrollment($student, $term, [$existing]);
        $token = $this->tokenForProgramHead('head.ineligible@grc.test');

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$existing->id, $outside->id], 'note' => 'Add subject.',
        ])->assertUnprocessable();

        self::assertSame(0, $outside->refresh()->enrolled_count);
        self::assertSame(EnrollmentStatus::PendingProgramHeadApproval, $enrollment->refresh()->status);
    }

    public function test_a_revision_can_swap_sections_of_the_same_eligible_subject(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $subject = $this->makeSubject('CS101');
        $first = $this->makeSection($term, $subject);
        $second = $this->makeSection($term, $subject, ['section_code' => 'B']);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$first]);
        $token = $this->tokenForProgramHead('head.section.swap@grc.test');

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$second->id], 'note' => 'Change section.',
        ])->assertOk();

        self::assertSame(0, $first->refresh()->enrolled_count);
        self::assertSame(1, $second->refresh()->enrolled_count);
    }

    public function test_a_chair_revision_supersedes_a_pending_student_counter_proposal(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $first = $this->makeSection($term, $this->makeSubject('CS101'));
        $second = $this->makeSection($term, $this->makeSubject('CS102'));
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$first]);
        $previous = EnrollmentRevision::create([
            'enrollment_id' => $enrollment->id, 'proposed_by' => $student->user_id,
            'note' => 'My proposal', 'added_subjects' => [], 'removed_subjects' => [],
            'units_before' => 3, 'units_after' => 3, 'status' => 'pending',
        ]);
        $token = $this->tokenForProgramHead('head.supersede@grc.test');

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$second->id], 'note' => 'Another option.',
        ])->assertOk()->assertJsonPath('data.revisions.0.status', 'superseded')
            ->assertJsonPath('data.revisions.1.status', 'pending');

        self::assertNotNull($previous->refresh()->responded_at);
    }

    public function test_a_program_head_can_swap_a_subject_for_another_and_the_seats_move(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $cs101 = $this->makeSubject('CS101');
        $cs102 = $this->makeSubject('CS102');
        $sectionA = $this->makeSection($term, $cs101);
        $sectionB = $this->makeSection($term, $cs102);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$sectionA]);
        $token = $this->tokenForProgramHead('head.swap@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$sectionB->id],
            'note' => 'The original section clashes with your other classes.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'pending_student_review')
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonPath('data.subjects.0.subject_code', 'CS102')
            ->assertJsonCount(1, 'data.revisions')
            ->assertJsonPath('data.revisions.0.note', 'The original section clashes with your other classes.')
            ->assertJsonPath('data.revisions.0.status', 'pending')
            ->assertJsonPath('data.revisions.0.removed_subjects.0.subject_code', 'CS101')
            ->assertJsonPath('data.revisions.0.added_subjects.0.subject_code', 'CS102');
        $enrollment->refresh();
        self::assertSame(EnrollmentStatus::PendingStudentReview, $enrollment->status);
        self::assertSame(3.0, $enrollment->total_units);
        self::assertSame([$sectionB->id], $enrollment->enrollmentSubjects()->where('status', EnrollmentSubjectStatus::Selected)->pluck('section_id')->all());
        self::assertSame(0, $sectionA->refresh()->enrolled_count);
        self::assertSame(1, $sectionB->refresh()->enrolled_count);

        $listResponse = $this->withToken($token)->getJson('/api/v1/enrollments');
        $listResponse->assertOk()
            ->assertJsonPath('data.0.id', $enrollment->id)
            ->assertJsonCount(1, 'data.0.subjects')
            ->assertJsonPath('data.0.subjects.0.subject_code', 'CS102');
        self::assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::ENROLLMENT_PROGRAM_HEAD_SUBJECTS_REVISED)->count(),
        );
    }

    public function test_revision_enforces_prerequisites_but_honors_a_registrar_waiver(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $intro = $this->makeSubject('CS101');
        $advanced = $this->makeSubject('CS201');
        $first = $this->makeSection($term, $intro);
        $second = $this->makeSection($term, $advanced);
        $student = $this->makeStudent($curriculum);
        $placement = CurriculumSubject::query()->where('curriculum_id', $curriculum->id)->where('subject_id', $advanced->id)->firstOrFail();
        SubjectPrerequisite::create(['curriculum_subject_id' => $placement->id, 'prerequisite_subject_id' => $intro->id, 'minimum_grade' => '3.00']);
        $enrollment = $this->makeEnrollment($student, $term, [$first]);
        $token = $this->tokenForProgramHead('head.prerequisite@grc.test');
        $url = "/api/v1/enrollments/{$enrollment->id}/subjects";
        $payload = ['section_ids' => [$first->id, $second->id], 'note' => 'Add subject with approved waiver.'];
        $this->withToken($token)->patchJson($url, $payload)->assertUnprocessable();
        self::assertSame(0, $second->refresh()->enrolled_count);
        $registrar = User::create(['name' => 'Registrar', 'email' => 'waiver.revise@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active]);
        EnrollmentSubjectWaiver::create(['student_id' => $student->id, 'subject_id' => $advanced->id, 'academic_term_id' => $term->id, 'reason' => 'Approved exception', 'granted_by' => $registrar->id, 'granted_at' => now()]);
        $this->withToken($token)->patchJson($url, $payload)->assertOk();
        self::assertSame(1, $second->refresh()->enrolled_count);
        $this->withToken($token)->patchJson($url, $payload)->assertUnprocessable();
        self::assertSame(1, $second->refresh()->enrolled_count);
        self::assertSame(1, EnrollmentRevision::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_a_dropped_section_is_revived_once_when_the_student_counter_proposes_it(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $first = $this->makeSection($term, $this->makeSubject('CS101'));
        $second = $this->makeSection($term, $this->makeSubject('CS102'));
        $student = $this->makeStudent($curriculum);
        $student->update(['enrollment_category' => 'irregular']);
        $enrollment = $this->makeEnrollment($student, $term, [$first]);
        $chair = $this->tokenForProgramHead('head.restore@grc.test');
        $url = "/api/v1/enrollments/{$enrollment->id}/subjects";
        $this->withToken($chair)->patchJson($url, ['section_ids' => [$second->id], 'note' => 'Try this subject.'])->assertOk();
        $this->app->make('auth')->forgetGuards();
        $token = (string) $this->postJson('/api/v1/auth/login', ['email' => $student->user->email, 'password' => self::PASSWORD])->json('data.token');
        $this->app->make('auth')->forgetGuards();
        $payload = ['section_ids' => [$first->id], 'note' => 'I prefer my original subject.'];
        $this->withToken($token)->patchJson($url, $payload)->assertOk();
        self::assertSame(1, $first->refresh()->enrolled_count);
        self::assertSame(0, $second->refresh()->enrolled_count);
        self::assertSame(2, EnrollmentSubject::query()->where('enrollment_id', $enrollment->id)->count());
        $this->withToken($token)->patchJson($url, $payload)->assertForbidden();
        self::assertSame(1, $first->refresh()->enrolled_count);
    }

    public function test_a_program_head_can_add_a_subject_recomputing_total_units(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $cs101 = $this->makeSubject('CS101', 3.0);
        $cs102 = $this->makeSubject('CS102', 2.0);
        $sectionA = $this->makeSection($term, $cs101);
        $sectionB = $this->makeSection($term, $cs102);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$sectionA]);
        $token = $this->tokenForProgramHead('head.add@grc.test');

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$sectionA->id, $sectionB->id],
            'note' => 'The original section clashes with your other classes.',
        ])->assertOk();

        self::assertSame(5.0, $enrollment->refresh()->total_units);
        self::assertSame(2, $enrollment->enrollmentSubjects()->where('status', EnrollmentSubjectStatus::Selected)->count());
    }

    public function test_a_full_section_cannot_be_added(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $cs101 = $this->makeSubject('CS101');
        $cs102 = $this->makeSubject('CS102');
        $sectionA = $this->makeSection($term, $cs101);
        $sectionB = $this->makeSection($term, $cs102, ['capacity' => 1, 'enrolled_count' => 1]);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$sectionA]);
        $token = $this->tokenForProgramHead('head.full@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$sectionA->id, $sectionB->id],
            'note' => 'The original section clashes with your other classes.',
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('section_ids', $response->json('error.errors'));
        self::assertSame(3.0, $enrollment->refresh()->total_units);
    }

    public function test_a_revision_cannot_push_the_student_past_the_hard_overload_ceiling(): void
    {
        config(['enrollment.max_regular_units' => 15, 'enrollment.overload_max_units' => 18]);
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $cs101 = $this->makeSubject('CS101', 3.0);
        $tooMuch = $this->makeSubject('CS999', 20.0);
        $sectionA = $this->makeSection($term, $cs101);
        $sectionHuge = $this->makeSection($term, $tooMuch);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$sectionA]);
        $token = $this->tokenForProgramHead('head.overload@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$sectionA->id, $sectionHuge->id],
            'note' => 'The original section clashes with your other classes.',
        ]);

        $response->assertUnprocessable();
        self::assertSame(3.0, $enrollment->refresh()->total_units);
    }

    public function test_a_program_head_cannot_revise_another_colleges_enrollment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $cs101 = $this->makeSubject('CS101');
        $sectionA = $this->makeSection($term, $cs101);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$sectionA]);
        $token = $this->tokenForProgramHead('head.otherswap@grc.test', CollegeCode::Coe);

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$sectionA->id],
            'note' => 'The original section clashes with your other classes.',
        ])->assertForbidden();
    }

    public function test_revision_is_rejected_once_the_registrar_stage_has_started(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $cs101 = $this->makeSubject('CS101');
        $sectionA = $this->makeSection($term, $cs101);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$sectionA]);
        $enrollment->update(['status' => EnrollmentStatus::PendingRegistrarApproval]);
        $token = $this->tokenForProgramHead('head.toolate@grc.test');

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$sectionA->id],
            'note' => 'The original section clashes with your other classes.',
        ])->assertUnprocessable();
    }

    public function test_approving_with_a_comment_notifies_the_student_with_it(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $cs101 = $this->makeSubject('CS101');
        $sectionA = $this->makeSection($term, $cs101);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$sectionA]);
        $token = $this->tokenForProgramHead('head.comment@grc.test');

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'program_head_approve',
            'program_head_comment' => 'Swapped CS102 for CS101 since the former is full.',
        ])->assertOk()->assertJsonPath('data.program_head_comment', 'Swapped CS102 for CS101 since the former is full.');

        $notification = Notification::query()->where('user_id', $student->user_id)->sole();
        self::assertSame(NotificationType::EnrollmentProgramHeadApproved, $notification->type);
        self::assertStringContainsString('Swapped CS102 for CS101', $notification->message);
    }

    public function test_a_decision_without_a_comment_leaves_it_null(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $cs101 = $this->makeSubject('CS101');
        $sectionA = $this->makeSection($term, $cs101);
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, [$sectionA]);
        $token = $this->tokenForProgramHead('head.nocomment@grc.test');

        $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'program_head_approve',
        ])->assertOk()->assertJsonPath('data.program_head_comment', null);
    }

    public function test_an_irregular_student_in_pending_student_review_can_propose_revised_subjects(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $cs101 = $this->makeSubject('CS101');
        $cs102 = $this->makeSubject('CS102');
        $cs103 = $this->makeSubject('CS103');
        $sectionA = $this->makeSection($term, $cs101);
        $sectionB = $this->makeSection($term, $cs102);
        $sectionC = $this->makeSection($term, $cs103);
        $student = $this->makeStudent($curriculum);
        $student->update(['enrollment_category' => 'irregular']);
        $enrollment = $this->makeEnrollment($student, $term, [$sectionA]);
        $chairToken = $this->tokenForProgramHead('head.propose@grc.test');

        // Chair revises: CS101 -> CS102 (status becomes pending_student_review)
        $this->withToken($chairToken)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$sectionB->id],
            'note' => 'CS101 full, take CS102.',
        ])->assertOk()->assertJsonPath('data.status', 'pending_student_review');

        // Clear guard cache before logging in as student
        $this->app->make('auth')->forgetGuards();

        // Student logs in
        $studentToken = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $student->user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
        $this->app->make('auth')->forgetGuards();

        // Student counter-proposes: take CS103 instead
        $response = $this->withToken($studentToken)->patchJson("/api/v1/enrollments/{$enrollment->id}/subjects", [
            'section_ids' => [$sectionC->id],
            'note' => 'I would prefer CS103 because of schedule.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'pending_program_head_approval')
            ->assertJsonPath('data.revisions.0.proposed_by_role', 'program_chair')
            ->assertJsonPath('data.revisions.1.proposed_by_role', 'student')
            ->assertJsonPath('data.revisions.1.status', 'pending')
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonPath('data.subjects.0.subject_code', 'CS103');

        $enrollment->refresh();
        self::assertSame(EnrollmentStatus::PendingProgramHeadApproval, $enrollment->status);
        self::assertSame(3.0, $enrollment->total_units);
        self::assertSame(0, $sectionB->refresh()->enrolled_count);
        self::assertSame(1, $sectionC->refresh()->enrolled_count);

        $this->app->make('auth')->forgetGuards();
        $this->withToken($chairToken)->patchJson("/api/v1/enrollments/{$enrollment->id}", [
            'action' => 'program_head_approve',
        ])->assertOk()->assertJsonPath('data.revisions.1.status', 'accepted');
    }
}
