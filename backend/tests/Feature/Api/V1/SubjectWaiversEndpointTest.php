<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\EnrollmentSubjectWaiver;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectPrerequisite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stakeholder Doc 14 / ADR 0031: the Registrar Head can let one student take
 * one subject in one term although a prerequisite (even a failed one) is not
 * met. Users are switched by building preconditions with Eloquent, never by
 * chaining `withToken()` for two different users in one test.
 */
final class SubjectWaiversEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private AcademicTerm $term;

    private Curriculum $curriculum;

    private Subject $intro;

    private Subject $advanced;

    private StudentProfile $student;

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
        $this->intro = $this->subject('CS101');
        $this->advanced = $this->subject('CS201');
        $this->place($this->intro, 1);
        $placement = $this->place($this->advanced, 2);
        SubjectPrerequisite::create([
            'curriculum_subject_id' => $placement->id, 'prerequisite_subject_id' => $this->intro->id, 'minimum_grade' => '3.00',
        ]);
        Section::create([
            'academic_term_id' => $this->term->id, 'subject_id' => $this->advanced->id,
            'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Published,
        ]);
        $this->student = $this->makeStudent('student.waiver@grc.test', '2026-0001');
    }

    private function subject(string $code): Subject
    {
        return Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => 3.0, 'status' => SubjectStatus::Active]);
    }

    private function place(Subject $subject, int $year): CurriculumSubject
    {
        return CurriculumSubject::create([
            'curriculum_id' => $this->curriculum->id, 'subject_id' => $subject->id,
            'year_level' => $year, 'semester' => '1st', 'is_required' => true,
        ]);
    }

    private function makeStudent(string $email, string $number): StudentProfile
    {
        $user = User::create([
            'name' => 'Waiver Student', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $number,
            'program_id' => $this->curriculum->program_id, 'curriculum_id' => $this->curriculum->id,
            'year_level' => 1, 'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function tokenForStaff(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return $this->login($email);
    }

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function waive(?StudentProfile $student = null, ?AcademicTerm $term = null, bool $revoked = false): EnrollmentSubjectWaiver
    {
        $head = User::query()->where('role', UserRole::RegistrarHead)->first()
            ?? User::create([
                'name' => 'Head', 'email' => 'head.fixture@grc.test', 'password' => self::PASSWORD,
                'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            ]);

        return EnrollmentSubjectWaiver::create([
            'student_id' => ($student ?? $this->student)->id,
            'subject_id' => $this->advanced->id,
            'academic_term_id' => ($term ?? $this->term)->id,
            'reason' => 'Approved by the Registrar Head.',
            'granted_by' => $head->id,
            'granted_at' => now(),
            'revoked_at' => $revoked ? now() : null,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function advancedEntryFor(StudentProfile $student): ?array
    {
        $token = $this->login($student->user->email);
        $rows = $this->withToken($token)
            ->getJson('/api/v1/eligible-subjects?academic_term_id='.$this->term->id)
            ->assertOk()
            ->json('data');

        return collect($rows)->firstWhere('code', 'CS201');
    }

    public function test_the_registrar_head_grants_a_waiver_once_and_it_is_audited(): void
    {
        $token = $this->tokenForStaff(UserRole::RegistrarHead, 'head.grant@grc.test');
        $payload = [
            'subject_id' => $this->advanced->id,
            'academic_term_id' => $this->term->id,
            'reason' => 'Failed CS101 but passed the bridging course.',
        ];

        $first = $this->withToken($token)->postJson("/api/v1/students/{$this->student->id}/subject-waivers", $payload);
        $first->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $first->assertJsonPath('data.subject_code', 'CS201');
        $first->assertJsonPath('data.is_active', true);

        $second = $this->withToken($token)->postJson("/api/v1/students/{$this->student->id}/subject-waivers", $payload);
        $second->assertOk();

        self::assertSame(1, EnrollmentSubjectWaiver::query()->count());
        $audit = AuditLog::query()->where('action', AuditAction::ENROLLMENT_SUBJECT_WAIVER_GRANTED)->sole();
        self::assertSame('Failed CS101 but passed the bridging course.', $audit->reason);
    }

    public function test_a_subject_outside_the_students_curriculum_cannot_be_waived(): void
    {
        $outside = $this->subject('ZZ999');
        $token = $this->tokenForStaff(UserRole::RegistrarHead, 'head.outside@grc.test');

        $this->withToken($token)->postJson("/api/v1/students/{$this->student->id}/subject-waivers", [
            'subject_id' => $outside->id,
            'academic_term_id' => $this->term->id,
            'reason' => 'Not in this curriculum.',
        ])->assertUnprocessable();

        self::assertSame(0, EnrollmentSubjectWaiver::query()->count());
    }

    public function test_a_reason_is_required(): void
    {
        $token = $this->tokenForStaff(UserRole::RegistrarHead, 'head.reason@grc.test');

        $this->withToken($token)->postJson("/api/v1/students/{$this->student->id}/subject-waivers", [
            'subject_id' => $this->advanced->id,
            'academic_term_id' => $this->term->id,
        ])->assertUnprocessable();
    }

    public function test_only_the_registrar_head_can_use_the_waiver_endpoints(): void
    {
        $waiver = $this->waive();

        foreach ([UserRole::RegistrarStaff, UserRole::ProgramChair, UserRole::Dean, UserRole::AdmissionStaff] as $index => $role) {
            $token = $this->tokenForStaff($role, "nohead.{$index}@grc.test");

            $this->withToken($token)
                ->getJson("/api/v1/students/{$this->student->id}/subject-waivers?academic_term_id={$this->term->id}")
                ->assertForbidden();
            $this->withToken($token)
                ->postJson("/api/v1/students/{$this->student->id}/subject-waivers", [
                    'subject_id' => $this->advanced->id, 'academic_term_id' => $this->term->id, 'reason' => 'Nope.',
                ])->assertForbidden();
            $this->withToken($token)->deleteJson("/api/v1/subject-waivers/{$waiver->id}")->assertForbidden();
        }

        self::assertNull($waiver->refresh()->revoked_at);
    }

    public function test_a_student_cannot_grant_themselves_a_waiver(): void
    {
        $token = $this->login($this->student->user->email);

        $this->withToken($token)->postJson("/api/v1/students/{$this->student->id}/subject-waivers", [
            'subject_id' => $this->advanced->id, 'academic_term_id' => $this->term->id, 'reason' => 'Please.',
        ])->assertForbidden();
    }

    public function test_the_overview_lists_waivers_and_the_subjects_blocked_only_by_a_prerequisite(): void
    {
        $token = $this->tokenForStaff(UserRole::RegistrarHead, 'head.overview@grc.test');

        $blocked = $this->withToken($token)
            ->getJson("/api/v1/students/{$this->student->id}/subject-waivers?academic_term_id={$this->term->id}")
            ->assertOk();
        $blocked->assertJsonCount(0, 'data');
        $blocked->assertJsonPath('meta.blocked_subjects.0.subject_code', 'CS201');
        self::assertStringContainsString('CS101', $blocked->json('meta.blocked_subjects.0.reasons.0'));

        $this->waive();

        $granted = $this->withToken($token)
            ->getJson("/api/v1/students/{$this->student->id}/subject-waivers?academic_term_id={$this->term->id}")
            ->assertOk();
        $granted->assertJsonCount(1, 'data');
        $granted->assertJsonCount(0, 'meta.blocked_subjects');
    }

    public function test_revoking_is_idempotent_and_a_later_grant_reactivates_the_same_row(): void
    {
        $waiver = $this->waive();
        $token = $this->tokenForStaff(UserRole::RegistrarHead, 'head.revoke@grc.test');

        $this->withToken($token)->deleteJson("/api/v1/subject-waivers/{$waiver->id}")
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->withToken($token)->deleteJson("/api/v1/subject-waivers/{$waiver->id}")->assertOk();
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::ENROLLMENT_SUBJECT_WAIVER_REVOKED)->count());

        $this->withToken($token)->postJson("/api/v1/students/{$this->student->id}/subject-waivers", [
            'subject_id' => $this->advanced->id, 'academic_term_id' => $this->term->id, 'reason' => 'Reinstated.',
        ])->assertCreated()->assertJsonPath('data.is_active', true);

        self::assertSame(1, EnrollmentSubjectWaiver::query()->count());
        self::assertNull($waiver->refresh()->revoked_at);
    }

    public function test_an_unmet_prerequisite_blocks_the_subject_until_a_waiver_exists(): void
    {
        $entry = $this->advancedEntryFor($this->student);
        self::assertFalse($entry['is_eligible']);
        self::assertSame('prerequisite', $entry['reasons'][0]['code']);

        $this->waive();

        $waived = $this->advancedEntryFor($this->student);
        self::assertTrue($waived['is_eligible']);
        self::assertContains('prerequisite_waived', array_column($waived['reasons'], 'code'));
        self::assertNotEmpty($waived['available_sections']);
    }

    public function test_a_revoked_or_other_term_or_other_student_waiver_changes_nothing(): void
    {
        $otherTerm = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::Draft,
        ]);
        $other = $this->makeStudent('student.other@grc.test', '2026-0002');
        $this->waive(revoked: true);
        $this->waive($this->student, $otherTerm);
        $this->waive($other);

        $entry = $this->advancedEntryFor($this->student);

        self::assertFalse($entry['is_eligible']);
    }

    public function test_a_waived_subject_can_actually_be_enrolled_and_an_unwaived_one_cannot(): void
    {
        $section = Section::query()->where('subject_id', $this->advanced->id)->sole();
        $token = $this->login($this->student->user->email);

        $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $this->term->id,
            'sections' => [['section_id' => $section->id]],
        ])->assertUnprocessable();

        $this->waive();

        $this->withToken($token)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $this->term->id,
            'sections' => [['section_id' => $section->id]],
        ])->assertCreated();
        self::assertSame(1, $section->refresh()->enrolled_count);
    }
}
