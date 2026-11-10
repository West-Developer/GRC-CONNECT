<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Academic\EvaluateCreditMappingStatus;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
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
use App\Models\AcademicTermSectionPlan;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Notification;
use App\Models\Program;
use App\Models\QueueTicket;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectPrerequisite;
use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approving a transferee's credits can change whether they are Regular or Irregular
 * (ADR 0043, amended 2026-10-05): a credited subject counts as completed, so a block
 * subject whose prerequisite is now credited no longer needs removing. The category is
 * re-derived the moment the credits count, not at some later refresh.
 */
final class TransfereeCreditReclassificationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private Subject $prerequisite;

    private StudentProfile $student;

    private ?AcademicTerm $term = null;

    /**
     * A year-2 transferee, Irregular because the one subject of their block (CS201) needs CS101,
     * which they have no credit for yet.
     */
    private function world(bool $withTerm = true): void
    {
        $this->term = $withTerm ? AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]) : null;
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);

        $this->prerequisite = Subject::create(['code' => 'CS101', 'title' => 'Intro', 'units' => 3, 'status' => SubjectStatus::Active]);
        $block = Subject::create(['code' => 'CS201', 'title' => 'Data Structures', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $this->prerequisite->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        $blockPlacement = CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $block->id, 'year_level' => 2, 'semester' => '2nd', 'is_required' => true]);
        SubjectPrerequisite::create([
            'curriculum_subject_id' => $blockPlacement->id, 'prerequisite_subject_id' => $this->prerequisite->id, 'minimum_grade' => '3.00',
        ]);

        if ($this->term !== null) {
            $plan = AcademicTermSectionPlan::create([
                'academic_term_id' => $this->term->id, 'curriculum_id' => $curriculum->id,
                'college' => 'ccs', 'year_level' => 2, 'section_count' => 1, 'students_per_block' => 40, 'status' => 'submitted',
            ]);
            Section::create([
                'academic_term_id' => $this->term->id, 'section_plan_id' => $plan->id, 'subject_id' => $block->id,
                'section_code' => 'IT201', 'schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
                'capacity' => 40, 'is_block_exclusive' => true, 'status' => SectionStatus::Published,
            ]);
        }

        $user = User::create([
            'name' => 'Maria Santos', 'email' => 'maria@grc.test', 'password' => self::PASSWORD,
            'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $this->student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-0900', 'program_id' => $program->id, 'curriculum_id' => $curriculum->id,
            'year_level' => 2, 'student_type' => StudentType::Transferee, 'enrollment_category' => 'irregular',
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function endorsedCreditForThePrerequisite(): TransfereeCredit
    {
        return TransfereeCredit::create([
            'student_id' => $this->student->id, 'source_institution' => 'Other University',
            'source_subject_code' => 'IT 100', 'source_subject_title' => 'Intro to Computing', 'source_grade' => '1.75',
            'credited_units' => 3, 'subject_id' => $this->prerequisite->id, 'status' => TransfereeCreditStatus::Endorsed,
        ]);
    }

    private function pendingCreditForThePrerequisite(): TransfereeCredit
    {
        return TransfereeCredit::create([
            'student_id' => $this->student->id, 'source_institution' => 'Other University',
            'source_subject_code' => 'IT 100', 'source_subject_title' => 'Intro to Computing', 'source_grade' => '1.75',
            'credited_units' => 3, 'subject_id' => $this->prerequisite->id, 'status' => TransfereeCreditStatus::Pending,
        ]);
    }

    private function asChair(): static
    {
        $chair = User::create([
            'name' => 'Program Chair', 'email' => 'chair@grc.test', 'password' => self::PASSWORD,
            'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            'college' => CollegeCode::Ccs,
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', ['email' => $chair->email, 'password' => self::PASSWORD])->json('data.token');
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function asHead(): static
    {
        $head = User::create([
            'name' => 'Registrar Head', 'email' => 'head@grc.test', 'password' => self::PASSWORD,
            'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', ['email' => $head->email, 'password' => self::PASSWORD])->json('data.token');
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_approving_all_of_a_students_credits_makes_an_irregular_transferee_regular_when_the_credit_covers_the_prerequisite(): void
    {
        $this->world();
        $this->endorsedCreditForThePrerequisite();

        $this->asHead()->postJson("/api/v1/student-profiles/{$this->student->id}/transferee-credits/approve-all")->assertOk();

        self::assertSame('regular', $this->student->fresh()->enrollment_category);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED)->where('auditable_id', $this->student->id)->count());
        self::assertSame(1, Notification::query()->where('user_id', $this->student->user_id)->where('type', NotificationType::EnrollmentCategoryReclassified)->count());
    }

    public function test_approving_one_credit_makes_an_irregular_transferee_regular_too(): void
    {
        $this->world();
        $credit = $this->endorsedCreditForThePrerequisite();

        $this->asHead()->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'approve'])->assertOk();

        self::assertSame('regular', $this->student->fresh()->enrollment_category);
    }

    public function test_rejecting_the_credit_leaves_the_category_alone(): void
    {
        $this->world();
        $credit = $this->endorsedCreditForThePrerequisite();

        $this->asHead()->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'reject', 'reason' => 'Not on the transcript.'])->assertOk();

        self::assertSame('irregular', $this->student->fresh()->enrollment_category);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED)->count());
    }

    /**
     * Owner, 2026-10-15: once the Program Chair has passed the credits to the Registrar Head the
     * student enrols on them, so a credit that is only endorsed already counts for Regular / Irregular
     * and for the queue number; the Head's approval no longer has to come first.
     */
    public function test_the_chair_passing_the_credits_on_makes_an_irregular_transferee_regular_and_gives_the_queue_number(): void
    {
        $this->world();
        $this->pendingCreditForThePrerequisite();

        $this->asChair()->postJson("/api/v1/student-profiles/{$this->student->id}/transferee-credits/submit-to-registrar-head")->assertOk();

        self::assertSame('regular', $this->student->fresh()->enrollment_category);
        self::assertSame(1, QueueTicket::query()->where('student_profile_id', $this->student->id)->count());
        self::assertSame(1, Notification::query()->where('user_id', $this->student->user_id)->where('type', NotificationType::TransfereeQueueNumberIssued)->count());
    }

    public function test_endorsing_the_last_pending_credit_one_by_one_does_the_same(): void
    {
        $this->world();
        $credit = $this->pendingCreditForThePrerequisite();

        $this->asChair()->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'endorse'])->assertOk();

        self::assertSame('regular', $this->student->fresh()->enrollment_category);
        self::assertSame(1, QueueTicket::query()->where('student_profile_id', $this->student->id)->count());
    }

    public function test_the_registrar_head_rejecting_an_endorsed_credit_takes_the_provisional_credit_back(): void
    {
        $this->world();
        $this->pendingCreditForThePrerequisite();
        $this->asChair()->postJson("/api/v1/student-profiles/{$this->student->id}/transferee-credits/submit-to-registrar-head")->assertOk();
        self::assertSame('regular', $this->student->fresh()->enrollment_category);
        $credit = TransfereeCredit::query()->where('student_id', $this->student->id)->sole();

        $this->asHead()->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'reject', 'reason' => 'Not on the transcript.'])->assertOk();

        self::assertSame('irregular', $this->student->fresh()->enrollment_category);
        self::assertSame(1, QueueTicket::query()->where('student_profile_id', $this->student->id)->count(), 'the number was issued once and stays');
    }

    public function test_the_registrar_head_returning_an_endorsed_credit_to_the_chair_takes_it_back_and_holds_enrolment_again(): void
    {
        $this->world();
        $this->pendingCreditForThePrerequisite();
        $this->asChair()->postJson("/api/v1/student-profiles/{$this->student->id}/transferee-credits/submit-to-registrar-head")->assertOk();
        $credit = TransfereeCredit::query()->where('student_id', $this->student->id)->sole();

        $this->asHead()->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'return', 'reason' => 'Check the units.'])->assertOk();

        self::assertSame('irregular', $this->student->fresh()->enrollment_category);
        self::assertFalse((new EvaluateCreditMappingStatus)->execute($this->student->fresh())->isCompleted);
    }

    public function test_the_head_approving_what_the_chair_passed_on_keeps_the_student_regular_and_adds_no_second_number(): void
    {
        $this->world();
        $this->pendingCreditForThePrerequisite();
        $this->asChair()->postJson("/api/v1/student-profiles/{$this->student->id}/transferee-credits/submit-to-registrar-head")->assertOk();

        $this->asHead()->postJson("/api/v1/student-profiles/{$this->student->id}/transferee-credits/approve-all")->assertOk();

        self::assertSame('regular', $this->student->fresh()->enrollment_category);
        self::assertSame(1, QueueTicket::query()->where('student_profile_id', $this->student->id)->count());
    }

    public function test_an_approval_with_no_term_in_progress_still_succeeds_and_changes_no_category(): void
    {
        $this->world(withTerm: false);
        $this->endorsedCreditForThePrerequisite();

        $this->asHead()->postJson("/api/v1/student-profiles/{$this->student->id}/transferee-credits/approve-all")->assertOk();

        self::assertSame('irregular', $this->student->fresh()->enrollment_category);
        self::assertSame(1, TransfereeCredit::query()->where('status', TransfereeCreditStatus::Approved)->count());
    }
}
