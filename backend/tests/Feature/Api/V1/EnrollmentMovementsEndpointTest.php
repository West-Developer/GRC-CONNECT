<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentChangeRequestStatus;
use App\Domain\Enrollment\EnrollmentChangeRequestType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\WithdrawalStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentChangeRequest;
use App\Models\Program;
use App\Models\ProgramShift;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stakeholder Doc 14 S20 (ADR 0034): Enrollment Analytics shows drops,
 * withdrawals, and course shifts, and the Registrar records a course shift.
 */
final class EnrollmentMovementsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private AcademicTerm $term;

    private Program $bsit;

    private Program $bscs;

    private Program $bsed;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $this->bsit = Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active]);
        $this->bscs = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active]);
        $this->bsed = Program::create(['code' => 'BSED', 'name' => 'BS Education', 'college' => CollegeCode::Coe, 'status' => ProgramStatus::Active]);
    }

    private function user(UserRole $role, ?CollegeCode $college = null): User
    {
        return User::create([
            'name' => 'Test '.$role->value.' '.++$this->seq,
            'email' => $role->value.'.'.$this->seq.'.moves@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'college' => $college,
            'status' => UserStatus::Active,
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('movements-test')->plainTextToken;
    }

    private function student(Program $program, bool $demo = false): StudentProfile
    {
        $user = $this->user(UserRole::Student);
        $curriculum = Curriculum::query()->where('program_id', $program->id)->first()
            ?? Curriculum::create(['program_id' => $program->id, 'name' => $program->code.' Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'MV-'.++$this->seq,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
            'is_demo_account' => $demo,
        ]);
    }

    private function enrollment(StudentProfile $student): Enrollment
    {
        return Enrollment::create(['student_id' => $student->id, 'academic_term_id' => $this->term->id, 'status' => EnrollmentStatus::Enrolled, 'enrolled_at' => now()]);
    }

    private function drop(StudentProfile $student, EnrollmentChangeRequestStatus $status = EnrollmentChangeRequestStatus::Approved): void
    {
        $subject = Subject::create(['code' => 'DRP'.++$this->seq, 'title' => 'Dropped', 'units' => 3, 'status' => SubjectStatus::Active]);
        EnrollmentChangeRequest::create([
            'enrollment_id' => $this->enrollment($student)->id,
            'type' => EnrollmentChangeRequestType::Drop,
            'subject_id' => $subject->id,
            'reason' => 'Schedule conflict.',
            'status' => $status,
        ]);
    }

    private function withdrawal(StudentProfile $student, WithdrawalStatus $status = WithdrawalStatus::Approved): void
    {
        WithdrawalRequest::create(['enrollment_id' => $this->enrollment($student)->id, 'reason' => 'Moving away.', 'status' => $status]);
    }

    private function report(User $viewer, string $type, string $extra = ''): TestResponse
    {
        return $this->withToken($this->token($viewer))
            ->getJson("/api/v1/analytics/enrollment-movements?academic_term_id={$this->term->id}&type={$type}{$extra}");
    }

    // --- drops and withdrawals -------------------------------------------

    public function test_approved_drops_are_counted_per_program_and_department(): void
    {
        $this->drop($this->student($this->bsit));
        $this->drop($this->student($this->bsit));
        $this->drop($this->student($this->bscs));
        $this->drop($this->student($this->bsed));
        // Not counted: still pending, rejected, and a demo account.
        $this->drop($this->student($this->bsit), EnrollmentChangeRequestStatus::Pending);
        $this->drop($this->student($this->bsit), EnrollmentChangeRequestStatus::Rejected);
        $this->drop($this->student($this->bsit, demo: true));

        $response = $this->report($this->user(UserRole::RegistrarHead), 'drops');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'enrollment_movements')
            ->assertJsonPath('data.movement', 'drops')
            ->assertJsonPath('data.total', 4);
        self::assertSame(
            [['label' => 'BSIT', 'count' => 2], ['label' => 'BSCS', 'count' => 1], ['label' => 'BSED', 'count' => 1]],
            array_map(fn (array $group): array => ['label' => $group['label'], 'count' => $group['count']], $response->json('data.groups')),
        );
        $byDepartment = collect($response->json('data.by_department'))->pluck('count', 'college')->all();
        self::assertSame(3, $byDepartment['ccs']);
        self::assertSame(1, $byDepartment['coe']);
        self::assertSame(0, $byDepartment['coa']);
    }

    public function test_approved_withdrawals_are_counted_and_nothing_names_a_student(): void
    {
        $student = $this->student($this->bsit);
        $this->withdrawal($student);
        $this->withdrawal($this->student($this->bsit), WithdrawalStatus::Pending);

        $response = $this->report($this->user(UserRole::RegistrarStaff), 'withdrawals');

        $response->assertOk()->assertJsonPath('data.total', 1);
        $response->assertDontSee($student->student_number);
    }

    public function test_a_program_head_sees_only_their_own_college(): void
    {
        $this->withdrawal($this->student($this->bsit));
        $this->withdrawal($this->student($this->bsed));

        $response = $this->report($this->user(UserRole::ProgramChair, CollegeCode::Ccs), 'withdrawals');

        $response->assertOk()->assertJsonPath('data.total', 1)->assertJsonCount(1, 'data.by_department');
        self::assertSame('ccs', $response->json('data.by_department.0.college'));
    }

    public function test_the_registrar_can_narrow_to_one_college(): void
    {
        $this->withdrawal($this->student($this->bsit));
        $this->withdrawal($this->student($this->bsed));

        $this->report($this->user(UserRole::RegistrarHead), 'withdrawals', '&college=coe')
            ->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_the_movement_type_and_term_are_validated(): void
    {
        $head = $this->user(UserRole::RegistrarHead);

        $this->report($head, 'transfers')->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['type']]]);
        $this->withToken($this->token($head))
            ->getJson('/api/v1/analytics/enrollment-movements?academic_term_id=99999&type=drops')
            ->assertUnprocessable();
    }

    public function test_other_roles_cannot_read_the_movements(): void
    {
        foreach ([UserRole::Student, UserRole::Faculty, UserRole::Dean, UserRole::AccountingStaff] as $role) {
            $this->report($this->user($role, CollegeCode::Ccs), 'drops')->assertForbidden();
            $this->flushHeaders();
        }
    }

    // --- course shifts ---------------------------------------------------

    public function test_the_registrar_records_a_shift_and_it_shows_from_to(): void
    {
        $student = $this->student($this->bsit);
        $registrar = $this->user(UserRole::RegistrarHead);
        $token = $this->token($registrar);

        $response = $this->withToken($token)->postJson('/api/v1/program-shifts', [
            'student_number' => $student->student_number,
            'to_program_id' => $this->bscs->id,
            'academic_term_id' => $this->term->id,
            'reason' => 'Changed course after the first year.',
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.from_program_id', $this->bsit->id)
            ->assertJsonPath('data.to_program_id', $this->bscs->id);
        // A record only: the student's own program is untouched.
        self::assertSame($this->bsit->id, $student->fresh()->program_id);

        $audit = AuditLog::query()->where('action', AuditAction::PROGRAM_SHIFT_RECORDED)->first();
        self::assertNotNull($audit);
        self::assertSame($this->bsit->id, $audit->before_values['program_id']);
        self::assertSame($this->bscs->id, $audit->after_values['program_id']);
        self::assertSame('Changed course after the first year.', $audit->reason);

        $report = $this->withToken($token)
            ->getJson("/api/v1/analytics/enrollment-movements?academic_term_id={$this->term->id}&type=shifts");
        $report->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.groups.0.label', 'BSIT → BSCS')
            ->assertJsonPath('data.groups.0.from_program_code', 'BSIT')
            ->assertJsonPath('data.groups.0.to_program_code', 'BSCS');
        $report->assertDontSee($student->student_number);
    }

    public function test_recording_the_same_shift_twice_changes_nothing_more(): void
    {
        $student = $this->student($this->bsit);
        $token = $this->token($this->user(UserRole::RegistrarStaff));
        $payload = [
            'student_number' => $student->student_number,
            'to_program_id' => $this->bscs->id,
            'academic_term_id' => $this->term->id,
            'reason' => 'Changed course.',
        ];

        $this->withToken($token)->postJson('/api/v1/program-shifts', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/v1/program-shifts', $payload)->assertOk();

        self::assertSame(1, ProgramShift::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::PROGRAM_SHIFT_RECORDED)->count());
    }

    public function test_a_shift_into_the_same_course_or_for_an_unknown_student_is_refused(): void
    {
        $student = $this->student($this->bsit);
        $token = $this->token($this->user(UserRole::RegistrarHead));

        $this->withToken($token)->postJson('/api/v1/program-shifts', [
            'student_number' => $student->student_number,
            'to_program_id' => $this->bsit->id,
            'academic_term_id' => $this->term->id,
            'reason' => 'Same course.',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['to_program_id']]]);

        $this->withToken($token)->postJson('/api/v1/program-shifts', [
            'student_number' => 'NO-SUCH',
            'to_program_id' => $this->bscs->id,
            'academic_term_id' => $this->term->id,
            'reason' => 'Nobody.',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['student_number']]]);

        self::assertSame(0, ProgramShift::query()->count());
    }

    public function test_a_reason_is_required_to_record_a_shift(): void
    {
        $student = $this->student($this->bsit);

        $this->withToken($this->token($this->user(UserRole::RegistrarHead)))->postJson('/api/v1/program-shifts', [
            'student_number' => $student->student_number,
            'to_program_id' => $this->bscs->id,
            'academic_term_id' => $this->term->id,
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['reason']]]);
    }

    public function test_only_the_registrar_can_record_a_shift(): void
    {
        $student = $this->student($this->bsit);

        foreach ([UserRole::ProgramChair, UserRole::Dean, UserRole::AdmissionStaff, UserRole::Student] as $role) {
            $this->withToken($this->token($this->user($role, CollegeCode::Ccs)))->postJson('/api/v1/program-shifts', [
                'student_number' => $student->student_number,
                'to_program_id' => $this->bscs->id,
                'academic_term_id' => $this->term->id,
                'reason' => 'Trying.',
            ])->assertForbidden();
            $this->flushHeaders();
        }

        self::assertSame(0, ProgramShift::query()->count());
    }

    public function test_a_program_head_sees_shifts_out_of_or_into_their_college(): void
    {
        $token = $this->token($this->user(UserRole::RegistrarHead));
        foreach ([[$this->bsit, $this->bscs], [$this->bsit, $this->bsed], [$this->bsed, $this->bscs]] as [$from, $to]) {
            $student = $this->student($from);
            $this->withToken($token)->postJson('/api/v1/program-shifts', [
                'student_number' => $student->student_number,
                'to_program_id' => $to->id,
                'academic_term_id' => $this->term->id,
                'reason' => 'Changed course.',
            ])->assertCreated();
            $this->flushHeaders();
        }

        // CCS: BSIT→BSCS (within), BSIT→BSED (out), BSED→BSCS (in): all three touch CCS.
        $this->report($this->user(UserRole::ProgramChair, CollegeCode::Ccs), 'shifts')
            ->assertOk()->assertJsonPath('data.total', 3);
        // A second actor in one test: drop the guard's cached user as well as the token.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        // COE: BSIT→BSED (in) and BSED→BSCS (out).
        $this->report($this->user(UserRole::ProgramChair, CollegeCode::Coe), 'shifts')
            ->assertOk()->assertJsonPath('data.total', 2);
    }
}
