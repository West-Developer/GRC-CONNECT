<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeMark;
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
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
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
 * The Registrar Head withdraws individual subjects of an enrolled student: the
 * subject is dropped from the enrollment, its seat is released once, and a
 * locked W grade shows on the Prospectus. Users are switched by building
 * preconditions with Eloquent, never by chaining `withToken()` for two users.
 */
final class SubjectWithdrawalsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private AcademicTerm $term;

    private Curriculum $curriculum;

    private StudentProfile $student;

    private Enrollment $enrollment;

    /** @var array<string, EnrollmentSubject> */
    private array $rows = [];

    /** @var array<string, Section> */
    private array $sections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $this->curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);

        $user = User::create([
            'name' => 'Withdrawn Student', 'email' => 'student.subjwd@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $this->student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-0001',
            'program_id' => $program->id, 'curriculum_id' => $this->curriculum->id,
            'year_level' => 1, 'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
        $this->enrollment = Enrollment::create([
            'student_id' => $this->student->id, 'academic_term_id' => $this->term->id,
            'status' => EnrollmentStatus::Enrolled, 'total_units' => 6, 'submitted_at' => now(),
            'registrar_decided_at' => now(), 'payment_confirmed_at' => now(), 'enrolled_at' => now(),
        ]);

        foreach (['CS101', 'CS102'] as $code) {
            $subject = Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => 3.0, 'status' => SubjectStatus::Active]);
            CurriculumSubject::create([
                'curriculum_id' => $this->curriculum->id, 'subject_id' => $subject->id,
                'year_level' => 1, 'semester' => '1st', 'is_required' => true,
            ]);
            $this->sections[$code] = Section::create([
                'academic_term_id' => $this->term->id, 'subject_id' => $subject->id, 'section_code' => $code.'-A',
                'capacity' => 40, 'enrolled_count' => 1, 'status' => SectionStatus::Published,
            ]);
            $this->rows[$code] = EnrollmentSubject::create([
                'enrollment_id' => $this->enrollment->id, 'section_id' => $this->sections[$code]->id,
                'status' => EnrollmentSubjectStatus::Enrolled,
            ]);
        }
    }

    private function token(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function url(): string
    {
        return "/api/v1/students/{$this->student->id}/subject-withdrawals";
    }

    public function test_the_registrar_head_sees_what_can_be_withdrawn(): void
    {
        $token = $this->token(UserRole::RegistrarHead, 'head.list@grc.test');

        $response = $this->withToken($token)->getJson($this->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $response->assertJsonCount(2, 'data.withdrawable');
        $response->assertJsonCount(0, 'data.withdrawn');
        $response->assertJsonPath('data.withdrawable.0.subject_code', 'CS101');
    }

    public function test_withdrawing_a_subject_drops_it_releases_one_seat_and_writes_a_locked_w_grade(): void
    {
        $token = $this->token(UserRole::RegistrarHead, 'head.withdraw@grc.test');

        $response = $this->withToken($token)->postJson($this->url(), [
            'enrollment_subject_ids' => [$this->rows['CS101']->id],
            'reason' => 'Withdrew after the midterm examination.',
        ])->assertOk();

        $response->assertJsonCount(1, 'data.withdrawable');
        $response->assertJsonPath('data.withdrawn.0.subject_code', 'CS101');
        $response->assertJsonPath('data.withdrawn.0.reason', 'Withdrew after the midterm examination.');

        self::assertSame(EnrollmentSubjectStatus::Dropped, $this->rows['CS101']->fresh()->status);
        self::assertSame(EnrollmentSubjectStatus::Enrolled, $this->rows['CS102']->fresh()->status);
        self::assertSame(0, $this->sections['CS101']->fresh()->enrolled_count);
        self::assertSame(1, $this->sections['CS102']->fresh()->enrolled_count);
        self::assertSame(EnrollmentStatus::Enrolled, $this->enrollment->fresh()->status);

        $grade = AcademicGrade::query()->sole();
        self::assertSame(GradeMark::Withdrawn, $grade->mark);
        self::assertSame(GradeStatus::Locked, $grade->status);
        self::assertNull($grade->final_grade);

        self::assertSame(1, AuditLog::query()->where('action', AuditAction::ENROLLMENT_SUBJECT_WITHDRAWN)->count());
        self::assertSame(1, Notification::query()->where('type', NotificationType::SubjectWithdrawn)->count());
    }

    public function test_the_prospectus_shows_the_withdrawn_subject(): void
    {
        $head = $this->token(UserRole::RegistrarHead, 'head.prospectus@grc.test');
        $this->withToken($head)->postJson($this->url(), [
            'enrollment_subject_ids' => [$this->rows['CS101']->id],
            'reason' => 'Withdrew after the midterm examination.',
        ])->assertOk();

        $entry = collect($this->withToken($head)->getJson("/api/v1/prospectus?student_id={$this->student->id}")
            ->assertOk()->json('data.semesters.0.entries'))->firstWhere('code', 'CS101');

        self::assertSame('W', $entry['mark']);
        self::assertSame('Withdrawn', $entry['mark_label']);
        self::assertSame('Withdrawn', $entry['status_label']);
    }

    public function test_withdrawing_the_same_subject_again_is_refused_and_changes_nothing(): void
    {
        $token = $this->token(UserRole::RegistrarHead, 'head.twice@grc.test');
        $payload = ['enrollment_subject_ids' => [$this->rows['CS101']->id], 'reason' => 'Withdrew after the midterm.'];

        $this->withToken($token)->postJson($this->url(), $payload)->assertOk();
        $this->withToken($token)->postJson($this->url(), $payload)->assertUnprocessable();

        self::assertSame(0, $this->sections['CS101']->fresh()->enrolled_count);
        self::assertSame(1, AcademicGrade::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::ENROLLMENT_SUBJECT_WITHDRAWN)->count());
    }

    public function test_withdrawing_the_last_subject_withdraws_the_enrollment(): void
    {
        $token = $this->token(UserRole::RegistrarHead, 'head.all@grc.test');

        $this->withToken($token)->postJson($this->url(), [
            'enrollment_subject_ids' => [$this->rows['CS101']->id, $this->rows['CS102']->id],
            'reason' => 'Left school for the rest of the term.',
        ])->assertOk();

        self::assertSame(EnrollmentStatus::Withdrawn, $this->enrollment->fresh()->status);
        self::assertSame(2, AcademicGrade::query()->where('mark', GradeMark::Withdrawn->value)->count());
    }

    public function test_a_draft_grade_is_turned_into_the_w_and_a_locked_grade_blocks_it(): void
    {
        $head = User::create([
            'name' => 'Head', 'email' => 'head.fixture@grc.test', 'password' => self::PASSWORD,
            'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $draft = AcademicGrade::create([
            'student_id' => $this->student->id, 'subject_id' => $this->sections['CS101']->subject_id,
            'section_id' => $this->sections['CS101']->id, 'academic_term_id' => $this->term->id,
            'mark' => GradeMark::Passed, 'final_grade' => '3.00', 'status' => GradeStatus::Draft, 'encoded_by' => $head->id,
        ]);
        AcademicGrade::create([
            'student_id' => $this->student->id, 'subject_id' => $this->sections['CS102']->subject_id,
            'section_id' => $this->sections['CS102']->id, 'academic_term_id' => $this->term->id,
            'mark' => GradeMark::Passed, 'final_grade' => '3.00', 'status' => GradeStatus::Locked, 'encoded_by' => $head->id,
        ]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'head.fixture@grc.test', 'password' => self::PASSWORD])->json('data.token');

        $this->withToken($token)->postJson($this->url(), [
            'enrollment_subject_ids' => [$this->rows['CS102']->id],
            'reason' => 'Already graded.',
        ])->assertUnprocessable();
        self::assertSame(EnrollmentSubjectStatus::Enrolled, $this->rows['CS102']->fresh()->status);

        $this->withToken($token)->postJson($this->url(), [
            'enrollment_subject_ids' => [$this->rows['CS101']->id],
            'reason' => 'Withdrew before grades were final.',
        ])->assertOk();

        self::assertSame(GradeMark::Withdrawn, $draft->fresh()->mark);
        self::assertSame(GradeStatus::Locked, $draft->fresh()->status);
    }

    public function test_a_reason_is_required_and_a_foreign_subject_is_refused(): void
    {
        $token = $this->token(UserRole::RegistrarHead, 'head.invalid@grc.test');

        $this->withToken($token)->postJson($this->url(), [
            'enrollment_subject_ids' => [$this->rows['CS101']->id],
        ])->assertUnprocessable();

        $otherUser = User::create([
            'name' => 'Other', 'email' => 'other.subjwd@grc.test', 'password' => self::PASSWORD,
            'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $other = StudentProfile::create([
            'user_id' => $otherUser->id, 'student_number' => '2026-0002',
            'program_id' => $this->curriculum->program_id, 'curriculum_id' => $this->curriculum->id,
            'year_level' => 1, 'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);

        $this->withToken($token)->postJson("/api/v1/students/{$other->id}/subject-withdrawals", [
            'enrollment_subject_ids' => [$this->rows['CS101']->id],
            'reason' => 'Wrong student.',
        ])->assertUnprocessable();

        self::assertSame(EnrollmentSubjectStatus::Enrolled, $this->rows['CS101']->fresh()->status);
    }

    public function test_a_closed_term_cannot_be_withdrawn_from(): void
    {
        $this->term->update(['status' => AcademicTermStatus::SemesterClosed]);
        $token = $this->token(UserRole::RegistrarHead, 'head.closed@grc.test');

        $this->withToken($token)->postJson($this->url(), [
            'enrollment_subject_ids' => [$this->rows['CS101']->id],
            'reason' => 'Too late.',
        ])->assertUnprocessable();
    }

    public function test_only_the_registrar_head_can_use_the_endpoints(): void
    {
        foreach ([UserRole::RegistrarStaff, UserRole::ProgramChair, UserRole::Faculty] as $index => $role) {
            $token = $this->token($role, "other.{$index}@grc.test");

            $this->withToken($token)->getJson($this->url())->assertForbidden();
            $this->withToken($token)->postJson($this->url(), [
                'enrollment_subject_ids' => [$this->rows['CS101']->id],
                'reason' => 'Not allowed.',
            ])->assertForbidden();
        }

        self::assertSame(EnrollmentSubjectStatus::Enrolled, $this->rows['CS101']->fresh()->status);
    }

    public function test_a_professor_cannot_encode_the_w_mark(): void
    {
        $token = $this->token(UserRole::Faculty, 'prof.w@grc.test');
        $professor = User::query()->where('email', 'prof.w@grc.test')->firstOrFail();
        $this->sections['CS101']->update(['professor_id' => $professor->id]);

        $this->withToken($token)->postJson('/api/v1/academic-grades', [
            'student_id' => $this->student->id,
            'subject_id' => $this->sections['CS101']->subject_id,
            'section_id' => $this->sections['CS101']->id,
            'academic_term_id' => $this->term->id,
            'mark' => 'W',
        ])->assertUnprocessable()->assertJsonPath('error.errors.mark.0', 'The selected mark is invalid.');
    }
}
