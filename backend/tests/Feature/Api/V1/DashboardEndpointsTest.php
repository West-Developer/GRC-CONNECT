<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
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
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeUser(UserRole $role, string $emailPrefix): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $emailPrefix.'.dashboard@grc.test',
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function makeActiveTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeProgramAndCurriculum(): Curriculum
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeStudent(Curriculum $curriculum, string $number): StudentProfile
    {
        $user = User::create([
            'name' => 'Student '.$number, 'email' => strtolower($number).'@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $number,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function makeEnrollment(StudentProfile $student, AcademicTerm $term, EnrollmentStatus $status, array $overrides = []): Enrollment
    {
        return Enrollment::create(array_merge([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => $status,
            'submitted_at' => now(),
        ], $overrides));
    }

    // --- Authorization matrix, verified live over real routes ---

    public function test_anonymous_requests_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/dashboards/enrollment-summary')->assertUnauthorized();
        $this->getJson('/api/v1/dashboards/institution-summary')->assertUnauthorized();
        $this->getJson('/api/v1/dashboards/policy-settings')->assertUnauthorized();
        $this->getJson('/api/v1/stuck-enrollments')->assertUnauthorized();
    }

    public function test_a_student_is_forbidden_from_every_dashboard_route(): void
    {
        $token = $this->tokenFor($this->makeUser(UserRole::Student, 'student'));

        $this->withToken($token)->getJson('/api/v1/dashboards/enrollment-summary')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/dashboards/institution-summary')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/dashboards/policy-settings')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/stuck-enrollments')->assertForbidden();
    }

    public function test_dean_may_view_enrollment_summary_and_stuck_enrollments_only(): void
    {
        $this->makeActiveTerm();
        $token = $this->tokenFor($this->makeUser(UserRole::Dean, 'dean'));

        $this->withToken($token)->getJson('/api/v1/dashboards/enrollment-summary')->assertOk();
        $this->withToken($token)->getJson('/api/v1/stuck-enrollments')->assertOk();
        $this->withToken($token)->getJson('/api/v1/dashboards/institution-summary')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/dashboards/policy-settings')->assertForbidden();
    }

    public function test_executive_director_may_view_both_summaries_but_not_stuck_enrollments_or_policy_settings(): void
    {
        $this->makeActiveTerm();
        $token = $this->tokenFor($this->makeUser(UserRole::ExecutiveDirector, 'exec'));

        $this->withToken($token)->getJson('/api/v1/dashboards/enrollment-summary')->assertOk();
        $this->withToken($token)->getJson('/api/v1/dashboards/institution-summary')->assertOk();
        $this->withToken($token)->getJson('/api/v1/stuck-enrollments')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/dashboards/policy-settings')->assertForbidden();
    }

    public function test_registrar_head_may_view_policy_settings_and_enrollment_views_but_not_the_institution_summary(): void
    {
        $this->makeActiveTerm();
        $token = $this->tokenFor($this->makeUser(UserRole::RegistrarHead, 'reghead'));

        $this->withToken($token)->getJson('/api/v1/dashboards/policy-settings')->assertOk();
        $this->withToken($token)->getJson('/api/v1/dashboards/enrollment-summary')->assertOk();
        $this->withToken($token)->getJson('/api/v1/dashboards/institution-summary')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/stuck-enrollments')->assertOk();
    }

    // --- Correctness: aggregation matches real data, no student identity leaks ---

    public function test_enrollment_summary_counts_match_seeded_fixtures(): void
    {
        $term = $this->makeActiveTerm();
        $curriculum = $this->makeProgramAndCurriculum();
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Intro', 'units' => 3, 'status' => SubjectStatus::Active]);
        Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'capacity' => 40, 'enrolled_count' => 2, 'status' => SectionStatus::Published,
        ]);
        Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'B',
            'capacity' => 30, 'status' => SectionStatus::Planned,
        ]);

        $s1 = $this->makeStudent($curriculum, 'STU-0001');
        $s2 = $this->makeStudent($curriculum, 'STU-0002');
        $this->makeEnrollment($s1, $term, EnrollmentStatus::Enrolled, [
            'registrar_decided_at' => now(), 'payment_confirmed_at' => now(), 'enrolled_at' => now(),
        ]);
        $this->makeEnrollment($s2, $term, EnrollmentStatus::PendingRegistrarApproval);

        AcademicGrade::create([
            'student_id' => $s1->id, 'subject_id' => $subject->id, 'academic_term_id' => $term->id,
            'final_grade' => '1.75', 'status' => GradeStatus::Locked, 'encoded_by' => $s1->user_id,
        ]);

        $token = $this->tokenFor($this->makeUser(UserRole::Dean, 'dean2'));
        $response = $this->withToken($token)->getJson('/api/v1/dashboards/enrollment-summary');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.status_counts.enrolled', 1);
        $response->assertJsonPath('data.status_counts.pending_registrar_approval', 1);
        $response->assertJsonPath('data.status_counts.draft', 0);
        $response->assertJsonPath('data.funnel_counts.submitted', 2);
        $response->assertJsonPath('data.funnel_counts.enrolled', 1);
        $response->assertJsonPath('data.total_sections', 2);
        $response->assertJsonPath('data.published_sections', 1);
        $response->assertJsonPath('data.total_capacity', 40);
        $response->assertJsonPath('data.total_enrolled_seats', 2);
        $response->assertJsonPath('data.grade_status_counts.locked', 1);

        $raw = (string) $response->getContent();
        self::assertStringNotContainsString('email', strtolower($raw));
        self::assertStringNotContainsString('"name"', strtolower($raw));
    }

    public function test_stuck_enrollments_excludes_enrolled_and_terminal_statuses(): void
    {
        $term = $this->makeActiveTerm();
        $curriculum = $this->makeProgramAndCurriculum();
        $inProgress = $this->makeStudent($curriculum, 'STU-1001');
        $alreadyEnrolled = $this->makeStudent($curriculum, 'STU-1002');
        $withdrawn = $this->makeStudent($curriculum, 'STU-1003');

        $this->makeEnrollment($inProgress, $term, EnrollmentStatus::PendingPayment, [
            'registrar_decided_at' => now()->subDays(3),
        ]);
        $this->makeEnrollment($alreadyEnrolled, $term, EnrollmentStatus::Enrolled, [
            'registrar_decided_at' => now(), 'payment_confirmed_at' => now(), 'enrolled_at' => now(),
        ]);
        $this->makeEnrollment($withdrawn, $term, EnrollmentStatus::Withdrawn);

        $token = $this->tokenFor($this->makeUser(UserRole::Dean, 'dean3'));
        $response = $this->withToken($token)->getJson('/api/v1/stuck-enrollments');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.student_number', 'STU-1001');
        $response->assertJsonPath('meta.threshold_configured', false);
        $response->assertJsonPath('data.0.is_flagged', false);

        self::assertSame(
            ['type', 'enrollment_id', 'student_number', 'status', 'status_label', 'days_in_status', 'is_flagged'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_stuck_enrollments_lists_a_student_waiting_at_the_payment_hold_with_the_days_since_their_review_finished(): void
    {
        $term = $this->makeActiveTerm();
        $curriculum = $this->makeProgramAndCurriculum();
        $waiting = $this->makeStudent($curriculum, 'STU-1101');

        $this->makeEnrollment($waiting, $term, EnrollmentStatus::AwaitingFirstPayment, [
            'program_head_decided_at' => now()->subDays(4),
        ]);

        $token = $this->tokenFor($this->makeUser(UserRole::Dean, 'dean-hold'));
        $response = $this->withToken($token)->getJson('/api/v1/stuck-enrollments')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.student_number', 'STU-1101');
        $response->assertJsonPath('data.0.status', 'awaiting_first_payment');
        $response->assertJsonPath('data.0.status_label', 'Awaiting First Payment');
        $response->assertJsonPath('data.0.days_in_status', 4);
    }

    public function test_stuck_enrollments_flags_rows_past_a_configured_threshold(): void
    {
        config(['enrollment.dashboard.stuck_threshold_days' => 2]);

        $term = $this->makeActiveTerm();
        $curriculum = $this->makeProgramAndCurriculum();
        $pastThreshold = $this->makeStudent($curriculum, 'STU-2001');
        $withinThreshold = $this->makeStudent($curriculum, 'STU-2002');

        $this->makeEnrollment($pastThreshold, $term, EnrollmentStatus::PendingRegistrarApproval, [
            'submitted_at' => now()->subDays(5),
        ]);
        $this->makeEnrollment($withinThreshold, $term, EnrollmentStatus::PendingRegistrarApproval, [
            'submitted_at' => now(),
        ]);

        $token = $this->tokenFor($this->makeUser(UserRole::Dean, 'dean4'));
        $response = $this->withToken($token)->getJson('/api/v1/stuck-enrollments');

        $response->assertJsonPath('meta.threshold_configured', true);
        $response->assertJsonPath('meta.threshold_days', 2);

        $rows = collect($response->json('data'))->keyBy('student_number');
        self::assertTrue($rows['STU-2001']['is_flagged']);
        self::assertFalse($rows['STU-2002']['is_flagged']);
    }

    public function test_policy_settings_reflects_current_config(): void
    {
        config(['enrollment.max_regular_units' => null]);
        config(['enrollment.dashboard.stuck_threshold_days' => null]);

        $token = $this->tokenFor($this->makeUser(UserRole::RegistrarHead, 'reghead2'));
        $response = $this->withToken($token)->getJson('/api/v1/dashboards/policy-settings');

        $response->assertOk();
        $values = collect($response->json('data.values'))->keyBy('key');

        self::assertSame('unset', $values['enrollment.max_regular_units']['status']);
        self::assertNull($values['enrollment.max_regular_units']['current_value']);
        self::assertSame('unset', $values['enrollment.dashboard.stuck_threshold_days']['status']);
        self::assertSame('no_mechanism', $values['sections.viability_threshold']['status']);
        self::assertNotNull($values['sections.viability_threshold']['prd_reference']);

        self::assertSame('provisional', $values['fees.tuition_per_unit']['status']);
        self::assertSame('200.00', $values['fees.tuition_per_unit']['current_value']);
        self::assertSame('provisional', $values['fees.miscellaneous']['status']);
        self::assertStringContainsString('Registration', $values['fees.miscellaneous']['current_value']);
    }

    public function test_institution_summary_counts_students_by_program(): void
    {
        $term = $this->makeActiveTerm();
        $curriculum = $this->makeProgramAndCurriculum();
        $s1 = $this->makeStudent($curriculum, 'STU-3001');
        $this->makeStudent($curriculum, 'STU-3002');
        $this->makeEnrollment($s1, $term, EnrollmentStatus::Draft);

        $token = $this->tokenFor($this->makeUser(UserRole::ExecutiveDirector, 'exec2'));
        $response = $this->withToken($token)->getJson('/api/v1/dashboards/institution-summary');

        $response->assertOk();
        $response->assertJsonPath('data.program_counts.BSCS', 2);
        $response->assertJsonPath('data.total_programs', 1);
        $response->assertJsonPath('data.active_programs', 1);
        $response->assertJsonPath('data.year_over_year.0.school_year', '2026-2027');
        $response->assertJsonPath('data.year_over_year.0.enrollment_count', 1);
    }

    public function test_institution_summary_filters_status_counts_by_academic_term(): void
    {
        $term1 = $this->makeActiveTerm();
        $term2 = AcademicTerm::create([
            'school_year' => '2025-2026',
            'semester' => '2nd',
            'status' => 'semester_closed',
            'is_active' => false,
            'starts_at' => now()->subYear(),
            'ends_at' => now()->subMonths(6),
        ]);
        $curriculum = $this->makeProgramAndCurriculum();
        $s1 = $this->makeStudent($curriculum, 'STU-4001');
        $s2 = $this->makeStudent($curriculum, 'STU-4002');
        $this->makeEnrollment($s1, $term1, EnrollmentStatus::PendingRegistrarApproval);
        $this->makeEnrollment($s2, $term2, EnrollmentStatus::Enrolled);

        $token = $this->tokenFor($this->makeUser(UserRole::ExecutiveDirector, 'exec3'));

        // Without term filter: all statuses
        $allResponse = $this->withToken($token)->getJson('/api/v1/dashboards/institution-summary');
        $allResponse->assertOk();
        $allResponse->assertJsonPath('data.status_counts.pending_registrar_approval', 1);
        $allResponse->assertJsonPath('data.status_counts.enrolled', 1);

        // With term1 filter: only term1's pending_registrar_approval
        $term1Response = $this->withToken($token)->getJson("/api/v1/dashboards/institution-summary?academic_term_id={$term1->id}");
        $term1Response->assertOk();
        $term1Response->assertJsonPath('data.status_counts.pending_registrar_approval', 1);
        $term1Response->assertJsonPath('data.status_counts.enrolled', 0);
    }
}
