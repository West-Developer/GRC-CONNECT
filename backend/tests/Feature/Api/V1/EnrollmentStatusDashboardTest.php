<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Dashboard\EnrollmentStatusGroup;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Enrollment Dashboard drill-down (ADR 0024): overview → sections →
 * students → one student. Numbers are per *student*, sections are a partition,
 * Program Chair is limited to their own college (Dean is institution-wide
 * here, stakeholder Doc 15), and every opened student list / detail is
 * audited.
 */
final class EnrollmentStatusDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private AcademicTerm $term;

    private Curriculum $ccs;

    private Curriculum $coe;

    private int $subjectSeq = 0;

    /** @var array<string, StudentProfile> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $this->ccs = $this->curriculum('BSIT', CollegeCode::Ccs);
        $this->coe = $this->curriculum('BSED', CollegeCode::Coe);
    }

    private function curriculum(string $code, CollegeCode $college): Curriculum
    {
        $program = Program::create([
            'code' => $code, 'name' => 'Program '.$code, 'college' => $college, 'status' => ProgramStatus::Active,
        ]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => $code.' Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function user(UserRole $role, string $prefix, ?CollegeCode $college = null): User
    {
        return User::create([
            'name' => 'Test '.$prefix,
            'email' => $prefix.'.enrollment-status@grc.test',
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'college' => $college,
        ]);
    }

    /** Signs in as one actor; safe to call repeatedly in the same test. */
    private function actingAsUser(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    /**
     * @param  array{user_status?: UserStatus, admission?: AdmissionStatus, graduated?: bool, demo?: bool}  $options
     */
    private function student(string $key, Curriculum $curriculum, array $options = []): StudentProfile
    {
        $user = User::create([
            'name' => 'Student '.$key,
            'email' => strtolower($key).'@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::Student,
            'status' => $options['user_status'] ?? UserStatus::Active,
        ]);

        return $this->students[$key] = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'SN-'.$key,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 2,
            'admission_status' => $options['admission'] ?? AdmissionStatus::Admitted,
            'graduation_school_year' => ($options['graduated'] ?? false) ? '2025-2026' : null,
            'academic_standing' => AcademicStanding::Good,
            'is_demo_account' => $options['demo'] ?? false,
        ]);
    }

    /**
     * @param  array<string, int>  $sections  section code => number of subjects taken in it
     */
    private function enrollment(StudentProfile $student, EnrollmentStatus $status, array $sections = []): Enrollment
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $this->term->id,
            'status' => $status,
            'total_units' => 3 * array_sum($sections),
            'submitted_at' => now(),
            'enrolled_at' => $status === EnrollmentStatus::Enrolled ? now() : null,
        ]);

        foreach ($sections as $code => $count) {
            for ($i = 0; $i < $count; $i++) {
                $subject = Subject::create([
                    'code' => 'SUB'.(++$this->subjectSeq), 'title' => 'Subject '.$this->subjectSeq,
                    'units' => 3, 'status' => SubjectStatus::Active,
                ]);
                $section = Section::create([
                    'academic_term_id' => $this->term->id, 'subject_id' => $subject->id,
                    'section_code' => (string) $code, 'capacity' => 40, 'status' => SectionStatus::Published,
                ]);
                EnrollmentSubject::create([
                    'enrollment_id' => $enrollment->id, 'section_id' => $section->id,
                    'status' => EnrollmentSubjectStatus::Enrolled,
                ]);
            }
        }

        return $enrollment;
    }

    /**
     * CCS: a1 enrolled (IT201 ×2, FIL201 ×1), a2 pending payment (IT201 ×2),
     * a3 not started, a4 rejected. Excluded: a5 graduated, a6 withdrawn,
     * a7 demo, a8 disabled account. COE: b1 enrolled (ED101 ×2), b2 not started.
     */
    private function scenario(): void
    {
        $this->enrollment($this->student('a1', $this->ccs), EnrollmentStatus::Enrolled, ['IT201' => 2, 'FIL201' => 1]);
        $this->enrollment($this->student('a2', $this->ccs), EnrollmentStatus::PendingPayment, ['IT201' => 2]);
        $this->student('a3', $this->ccs);
        $this->enrollment($this->student('a4', $this->ccs), EnrollmentStatus::Rejected);
        $this->student('a5', $this->ccs, ['user_status' => UserStatus::Disabled, 'admission' => AdmissionStatus::Graduated, 'graduated' => true]);
        $this->student('a6', $this->ccs, ['admission' => AdmissionStatus::Withdrawn]);
        $this->student('a7', $this->ccs, ['demo' => true]);
        $this->student('a8', $this->ccs, ['user_status' => UserStatus::Disabled]);
        $this->enrollment($this->student('b1', $this->coe), EnrollmentStatus::Enrolled, ['ED101' => 2]);
        $this->student('b2', $this->coe);
    }

    // --- Authorization --------------------------------------------------

    public function test_anonymous_requests_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/dashboards/enrollment-status')->assertUnauthorized();
        $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs')->assertUnauthorized();
    }

    public function test_roles_outside_the_dashboard_audience_are_forbidden(): void
    {
        $this->actingAsUser($this->user(UserRole::Student, 'student'));

        $this->getJson('/api/v1/dashboards/enrollment-status')->assertForbidden();
        $this->getJson('/api/v1/dashboards/enrollment-status/sections?department=ccs')->assertForbidden();
        $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs')->assertForbidden();

        // Professors are not part of the audience either (stakeholder Doc 14).
        $this->actingAsUser($this->user(UserRole::Faculty, 'professor'));
        $this->getJson('/api/v1/dashboards/enrollment-status')->assertForbidden();
        $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs')->assertForbidden();
    }

    // --- Overview -------------------------------------------------------

    public function test_the_registrar_head_overview_counts_every_eligible_student_once_by_group(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));

        $response = $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk();

        $response->assertJsonPath('data.type', 'enrollment_status_overview')
            ->assertJsonPath('data.academic_term_id', $this->term->id)
            ->assertJsonPath('data.total_students', 6)
            ->assertJsonPath('data.groups.enrolled', 2)
            ->assertJsonPath('data.groups.in_progress', 1)
            ->assertJsonPath('data.groups.not_yet_done', 2)
            ->assertJsonPath('data.groups.not_enrolled', 1)
            ->assertJsonPath('data.steps.draft', 0)
            ->assertJsonPath('data.steps.pending_registrar_approval', 0)
            ->assertJsonPath('data.steps.pending_payment', 1)
            ->assertJsonPath('data.steps.enrolled', 2);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));

        $departments = collect($response->json('data.departments'))->keyBy('department');
        self::assertSame(['ccs', 'coe', 'coa', 'cbae'], $departments->keys()->all());
        self::assertSame(4, $departments['ccs']['total']);
        self::assertSame(1, $departments['ccs']['groups']['not_enrolled']);
        self::assertSame(2, $departments['coe']['total']);
        self::assertSame(0, $departments['coa']['total']);
        self::assertSame(0, $departments['cbae']['total']);
    }

    public function test_a_student_at_the_payment_hold_is_ongoing_has_their_own_step_and_is_waiting_on_the_cashier(): void
    {
        // A transferee's chosen subjects wait at `awaiting_first_payment` for the Cashier (ADR 0043, amended).
        $this->enrollment($this->student('h1', $this->ccs), EnrollmentStatus::AwaitingFirstPayment, ['IT201' => 1]);
        $this->enrollment($this->student('h2', $this->ccs), EnrollmentStatus::PendingRegistrarApproval, ['IT201' => 1]);

        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));
        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()
            ->assertJsonPath('data.groups.in_progress', 2)
            ->assertJsonPath('data.steps.awaiting_first_payment', 1)
            ->assertJsonPath('data.steps.pending_registrar_approval', 1);
        self::assertSame(EnrollmentStatusGroup::InProgress, EnrollmentStatusGroup::forStatus(EnrollmentStatus::AwaitingFirstPayment));

        // The Cashier sees only the students waiting on them: the one at the hold, not the Registrar's.
        $this->actingAsUser($this->user(UserRole::AccountingStaff, 'cashier'));
        $numbers = collect($this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs')->assertOk()->json('data'))
            ->pluck('student_number')->all();
        self::assertSame(['SN-h1'], $numbers);
    }

    public function test_the_overview_carries_no_student_identity(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::ExecutiveDirector, 'exec'));

        $body = $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()->getContent();

        self::assertStringNotContainsString('SN-a1', (string) $body);
        self::assertStringNotContainsString('Student a1', (string) $body);
    }

    public function test_a_dean_sees_every_department_but_a_program_chair_only_their_own(): void
    {
        $this->scenario();

        // Read-only monitoring, institution-wide, same as Registrar Head/Executive Director — a
        // Dean's own-college *approval* authority elsewhere is a separate concern this does not touch.
        $this->actingAsUser($this->user(UserRole::Dean, 'dean', CollegeCode::Ccs));
        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()
            ->assertJsonPath('data.total_students', 6)
            ->assertJsonCount(4, 'data.departments');

        $this->actingAsUser($this->user(UserRole::ProgramChair, 'chair', CollegeCode::Coe));
        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()
            ->assertJsonPath('data.total_students', 2)
            ->assertJsonCount(1, 'data.departments')
            ->assertJsonPath('data.departments.0.department', 'coe');
    }

    public function test_a_program_chair_without_a_college_is_unscoped_and_so_is_a_dean_without_one(): void
    {
        $this->scenario();

        $this->actingAsUser($this->user(UserRole::ProgramChair, 'chair-any'));
        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()
            ->assertJsonPath('data.total_students', 6);

        // A Dean carries no college scope for this read-only dashboard at all, so one with no
        // college assigned sees the same institution-wide numbers as one with a college.
        $this->actingAsUser($this->user(UserRole::Dean, 'dean-none'));
        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()
            ->assertJsonPath('data.total_students', 6);
    }

    public function test_a_student_with_a_cancelled_and_a_live_enrollment_is_counted_once(): void
    {
        $student = $this->student('c1', $this->ccs);
        $this->enrollment($student, EnrollmentStatus::Cancelled);
        $this->enrollment($student, EnrollmentStatus::PendingRegistrarApproval, ['IT201' => 1]);
        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));

        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()
            ->assertJsonPath('data.total_students', 1)
            ->assertJsonPath('data.groups.in_progress', 1)
            ->assertJsonPath('data.groups.not_enrolled', 0)
            ->assertJsonPath('data.steps.pending_registrar_approval', 1);
    }

    public function test_the_overview_is_not_found_without_an_active_term(): void
    {
        $this->term->update(['status' => AcademicTermStatus::Archived]);
        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));

        $this->getJson('/api/v1/dashboards/enrollment-status')->assertNotFound();
    }

    // --- Sections -------------------------------------------------------

    public function test_sections_partition_a_departments_students_and_list_no_section_last(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));

        $response = $this->getJson('/api/v1/dashboards/enrollment-status/sections?department=ccs')->assertOk();

        $response->assertJsonPath('data.type', 'enrollment_status_sections')
            ->assertJsonPath('data.department', 'ccs')
            ->assertJsonCount(2, 'data.sections')
            // a1 takes FIL201 once but IT201 twice, so IT201 is their section.
            ->assertJsonPath('data.sections.0.section_code', 'IT201')
            ->assertJsonPath('data.sections.0.total', 2)
            ->assertJsonPath('data.sections.0.groups.enrolled', 1)
            ->assertJsonPath('data.sections.0.groups.in_progress', 1)
            ->assertJsonPath('data.sections.1.section_code', null)
            ->assertJsonPath('data.sections.1.total', 2)
            ->assertJsonPath('data.sections.1.groups.not_yet_done', 1)
            ->assertJsonPath('data.sections.1.groups.not_enrolled', 1);

        // Sections add up to the department total from the overview.
        self::assertSame(4, collect($response->json('data.sections'))->sum('total'));
    }

    public function test_a_section_tie_is_broken_by_section_code(): void
    {
        $this->enrollment($this->student('t1', $this->ccs), EnrollmentStatus::PendingPayment, ['ZZ900' => 2, 'AA100' => 2]);
        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));

        $this->getJson('/api/v1/dashboards/enrollment-status/sections?department=ccs')->assertOk()
            ->assertJsonCount(1, 'data.sections')
            ->assertJsonPath('data.sections.0.section_code', 'AA100');
    }

    public function test_a_dean_can_open_any_departments_sections_or_students(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::Dean, 'dean', CollegeCode::Ccs));

        $this->getJson('/api/v1/dashboards/enrollment-status/sections?department=coe')->assertOk();
        $this->getJson('/api/v1/dashboards/enrollment-status/students?department=coe')->assertOk();
        $this->getJson('/api/v1/dashboards/enrollment-status/sections?department=ccs')->assertOk();
    }

    // --- Student list ---------------------------------------------------

    public function test_the_student_list_filters_by_section_group_and_no_section(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));

        $numbers = fn (string $query): array => collect(
            $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs&'.$query)
                ->assertOk()->json('data'),
        )->pluck('student_number')->all();

        self::assertSame(['SN-a1', 'SN-a2', 'SN-a3', 'SN-a4'], $numbers(''));
        self::assertSame(['SN-a1', 'SN-a2'], $numbers('section_code=IT201'));
        self::assertSame(['SN-a3', 'SN-a4'], $numbers('without_section=1'));
        self::assertSame(['SN-a1'], $numbers('group=enrolled'));
        self::assertSame(['SN-a3'], $numbers('group=not_yet_done'));
        self::assertSame(['SN-a4'], $numbers('group=not_enrolled&without_section=1'));
    }

    public function test_a_student_row_has_a_narrow_field_set_and_the_right_group(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));

        $row = $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs&group=enrolled')
            ->assertOk()->json('data.0');

        self::assertSame(
            [
                'type', 'student_profile_id', 'student_number', 'student_name', 'program_code', 'program_name',
                'department', 'year_level', 'section_code', 'group', 'group_label', 'enrollment_id',
                'enrollment_status', 'enrollment_status_label', 'submitted_at', 'enrolled_at',
            ],
            array_keys($row),
        );
        self::assertSame('Student a1', $row['student_name']);
        self::assertSame('IT201', $row['section_code']);
        self::assertSame('enrolled', $row['group']);
        self::assertSame('BSIT', $row['program_code']);
    }

    public function test_the_student_list_paginates_and_audits_once_per_opened_list(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));

        $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs&per_page=2&page=1')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs&per_page=2&page=2')->assertOk()
            ->assertJsonCount(2, 'data');

        $audits = AuditLog::query()->where('action', AuditAction::ENROLLMENT_STATUS_STUDENT_LIST_VIEWED)->get();
        self::assertCount(1, $audits);
        self::assertSame('ccs', $audits->first()->after_values['department']);
        self::assertSame(4, $audits->first()->after_values['result_count']);
    }

    public function test_the_student_list_validates_its_filters(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));

        $this->getJson('/api/v1/dashboards/enrollment-status/students')->assertUnprocessable();
        $this->getJson('/api/v1/dashboards/enrollment-status/students?department=nope')->assertUnprocessable();
        $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs&group=nope')->assertUnprocessable();
        $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs&per_page=51')->assertUnprocessable();
        $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs&section_code=IT201&without_section=1')
            ->assertUnprocessable();
    }

    // --- Student detail -------------------------------------------------

    public function test_a_student_detail_shows_the_enrollment_but_no_contact_grades_or_payments(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar'));
        $id = $this->students['a1']->id;

        $response = $this->getJson("/api/v1/dashboards/enrollment-status/students/{$id}")->assertOk();

        $response->assertJsonPath('data.type', 'enrollment_status_student_detail')
            ->assertJsonPath('data.student_number', 'SN-a1')
            ->assertJsonPath('data.student_name', 'Student a1')
            ->assertJsonPath('data.department', 'ccs')
            ->assertJsonPath('data.group', 'enrolled')
            ->assertJsonPath('data.section_code', 'IT201')
            ->assertJsonPath('data.enrollment.status', 'enrolled')
            ->assertJsonPath('data.enrollment.total_units', 9)
            ->assertJsonCount(3, 'data.subjects');

        // The timeline may say *when* payment was confirmed, but no contact,
        // grade or money field may appear anywhere in the payload.
        $data = $response->json('data');
        $keys = [];
        array_walk_recursive($data, function ($value, $key) use (&$keys): void {
            $keys[] = (string) $key;
        });
        foreach (['email', 'password', 'phone', 'mobile', 'address', 'mark', 'final_grade', 'amount', 'total_amount', 'balance'] as $forbidden) {
            self::assertNotContains($forbidden, $keys, "Detail exposes '{$forbidden}'.");
        }

        self::assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::ENROLLMENT_STATUS_STUDENT_VIEWED)
                ->where('auditable_id', $id)->count(),
        );
    }

    public function test_a_detail_for_a_student_who_has_not_started_has_no_enrollment(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::ExecutiveDirector, 'exec'));
        $id = $this->students['a3']->id;

        $this->getJson("/api/v1/dashboards/enrollment-status/students/{$id}")->assertOk()
            ->assertJsonPath('data.group', 'not_yet_done')
            ->assertJsonPath('data.enrollment', null)
            ->assertJsonPath('data.section_code', null)
            ->assertJsonCount(0, 'data.subjects');
    }

    public function test_a_dean_can_open_a_student_from_any_college_but_demo_students_stay_hidden(): void
    {
        $this->scenario();
        $this->actingAsUser($this->user(UserRole::Dean, 'dean', CollegeCode::Ccs));

        // b1 is College of Education, not the Dean's own CCS: still visible, institution-wide.
        $this->getJson('/api/v1/dashboards/enrollment-status/students/'.$this->students['b1']->id)->assertOk();
        // a7 is a demo account, hidden from every role regardless of college scope.
        $this->getJson('/api/v1/dashboards/enrollment-status/students/'.$this->students['a7']->id)->assertNotFound();
        $this->getJson('/api/v1/dashboards/enrollment-status/students/'.$this->students['a1']->id)->assertOk();
        $this->getJson('/api/v1/dashboards/enrollment-status/students/999999')->assertNotFound();
    }

    // --- Registrar / Accounting / Admission: only their own stage ---------

    public function test_registrar_staff_see_only_the_students_waiting_for_the_registrars_approval(): void
    {
        $this->scenario();
        $waiting = $this->student('c1', $this->ccs);
        $this->enrollment($waiting, EnrollmentStatus::PendingRegistrarApproval, ['IT301' => 1]);
        $this->actingAsUser($this->user(UserRole::RegistrarStaff, 'registrar-staff'));

        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()
            ->assertJsonPath('data.total_students', 1)
            ->assertJsonPath('data.groups.in_progress', 1)
            ->assertJsonPath('data.groups.enrolled', 0)
            ->assertJsonPath('data.groups.not_yet_done', 0)
            ->assertJsonPath('data.steps.pending_registrar_approval', 1)
            ->assertJsonPath('data.steps.pending_payment', 0);

        $list = $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs')->assertOk();
        self::assertSame(['SN-c1'], array_column($list->json('data'), 'student_number'));
    }

    public function test_a_stage_limited_role_cannot_open_a_student_outside_its_stage(): void
    {
        $this->scenario();
        $this->enrollment($this->student('c1', $this->ccs), EnrollmentStatus::PendingRegistrarApproval, ['IT301' => 1]);
        $this->actingAsUser($this->user(UserRole::RegistrarStaff, 'registrar-staff-detail'));

        // a2 is at the payment stage, a1 is already enrolled: neither is theirs.
        $this->getJson('/api/v1/dashboards/enrollment-status/students/'.$this->students['a2']->id)->assertNotFound();
        $this->getJson('/api/v1/dashboards/enrollment-status/students/'.$this->students['a1']->id)->assertNotFound();
        $this->getJson('/api/v1/dashboards/enrollment-status/students/'.$this->students['c1']->id)->assertOk();
    }

    public function test_accounting_staff_see_only_the_students_at_the_payment_stage(): void
    {
        $this->scenario();
        $this->enrollment($this->student('c1', $this->ccs), EnrollmentStatus::PendingRegistrarApproval, ['IT301' => 1]);
        $this->actingAsUser($this->user(UserRole::AccountingStaff, 'accounting-staff'));

        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()
            ->assertJsonPath('data.total_students', 1)
            ->assertJsonPath('data.steps.pending_payment', 1)
            ->assertJsonPath('data.steps.pending_registrar_approval', 0);

        $list = $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs')->assertOk();
        self::assertSame(['SN-a2'], array_column($list->json('data'), 'student_number'));
        $this->getJson('/api/v1/dashboards/enrollment-status/students/'.$this->students['c1']->id)->assertNotFound();
    }

    public function test_admission_staff_see_only_students_still_in_the_admission_process(): void
    {
        $this->student('p1', $this->ccs, ['admission' => AdmissionStatus::Pending]);
        $this->student('p2', $this->ccs, ['admission' => AdmissionStatus::Admitted]);
        $this->student('e1', $this->ccs, ['admission' => AdmissionStatus::Enrolled]);
        $this->actingAsUser($this->user(UserRole::AdmissionStaff, 'admission-staff'));

        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()
            ->assertJsonPath('data.total_students', 2);

        $list = $this->getJson('/api/v1/dashboards/enrollment-status/students?department=ccs')->assertOk();
        $numbers = array_column($list->json('data'), 'student_number');
        sort($numbers);
        self::assertSame(['SN-p1', 'SN-p2'], $numbers);
        $this->getJson('/api/v1/dashboards/enrollment-status/students/'.$this->students['e1']->id)->assertNotFound();
    }

    public function test_the_staff_roles_do_not_gain_the_enrollment_summary(): void
    {
        $this->scenario();

        foreach ([UserRole::RegistrarStaff, UserRole::AccountingStaff, UserRole::AdmissionStaff] as $role) {
            $this->actingAsUser($this->user($role, 'summary-'.$role->value));
            $this->getJson('/api/v1/dashboards/enrollment-summary')->assertForbidden();
        }
    }

    public function test_the_registrar_head_and_the_dean_still_see_every_stage(): void
    {
        $this->scenario();
        $this->enrollment($this->student('c1', $this->ccs), EnrollmentStatus::PendingRegistrarApproval, ['IT301' => 1]);

        $this->actingAsUser($this->user(UserRole::RegistrarHead, 'registrar-all'));
        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()->assertJsonPath('data.total_students', 7);

        $this->actingAsUser($this->user(UserRole::Dean, 'dean-all', CollegeCode::Ccs));
        $this->getJson('/api/v1/dashboards/enrollment-status')->assertOk()->assertJsonPath('data.total_students', 7);
    }

    public function test_the_in_progress_group_is_now_called_ongoing(): void
    {
        self::assertSame('Ongoing', EnrollmentStatusGroup::InProgress->label());
    }
}
