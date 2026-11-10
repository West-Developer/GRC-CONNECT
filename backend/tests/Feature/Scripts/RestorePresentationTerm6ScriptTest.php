<?php

namespace Tests\Feature\Scripts;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\ScheduleProposal;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBlockSections;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/scripts/restore_presentation_term6.php';

/**
 * `scripts/restore_presentation_term6.php` puts the database back into the presentation state: the target
 * term (2025-2026 · 2nd) current, its data untouched, and everything produced for later terms and by test
 * accounts since then deleted. A world is built the way production looks (an archived target term with real
 * enrollments, grades and payments; a later term with sections, enrollments, a queue ticket and a proposal; two
 * test student accounts with audit rows and tokens) and the script is run against it.
 */
final class RestorePresentationTerm6ScriptTest extends TestCase
{
    use BuildsBlockSections;
    use BuildsFreshmen;
    use RefreshDatabase;

    private AcademicTerm $history;

    private AcademicTerm $target;

    private AcademicTerm $ahead;

    private User $admin;

    private User $testUserOne;

    private User $testUserTwo;

    private StudentProfile $realOne;

    private StudentProfile $realThree;

    private Enrollment $aheadTestEnrollment;

    private Enrollment $aheadRealEnrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->staffUser(UserRole::RegistrarHead, 'head@grc.test');

        $this->history = AcademicTerm::unguarded(fn () => AcademicTerm::create([
            'school_year' => '2024-2025', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived,
        ]));
        $this->target = AcademicTerm::unguarded(fn () => AcademicTerm::create([
            'school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived,
            'starts_at' => '2026-01-15 08:00:00', 'ends_at' => '2026-06-30 17:00:00',
            'enrollment_opens_at' => '2026-01-15 08:00:00', 'enrollment_closes_at' => '2026-05-31 17:00:00',
            'closed_at' => '2026-10-03 08:10:27', 'archived_at' => '2026-10-03 08:10:27',
        ]));
        $this->ahead = AcademicTerm::unguarded(fn () => AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
            'enrollment_opens_at' => '2026-10-03 00:00:00', 'enrollment_closes_at' => '2026-10-27 15:59:00',
        ]));

        foreach ([$this->target, $this->ahead] as $term) {
            foreach (['irregular', 'late_enrollee', 'year_1', 'year_2', 'year_3', 'year_4'] as $audience) {
                DB::table('academic_term_enrollment_windows')->insert([
                    'academic_term_id' => $term->id, 'audience' => $audience,
                    'opens_at' => $term->enrollment_opens_at, 'closes_at' => $term->enrollment_closes_at,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (['ccs', 'coe', 'coa', 'cbae'] as $college) {
                DB::table('academic_term_college_workflows')->insert([
                    'academic_term_id' => $term->id, 'college' => $college, 'stage' => 'for_dean_approval',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        DB::table('academic_term_current_slots')->updateOrInsert(['id' => 1], ['academic_term_id' => $this->ahead->id, 'created_at' => now(), 'updated_at' => now()]);

        // Real students: two enrolled in the target term, one of them also enrolled in the later term.
        $this->realOne = $this->freshmanProfile('2025-06-00001', StudentType::Returnee);
        $realTwo = $this->freshmanProfile('2025-06-00002', StudentType::Returnee);
        $this->realThree = $this->realOne;
        $curriculum = $this->realOne->curriculum;

        $targetPlan = $this->yearOnePlan($this->target, $curriculum);
        $targetSections = [
            $this->blockSection($this->target, $targetPlan, 'TGT-1', 'TGT1'),
            $this->blockSection($this->target, $targetPlan, 'TGT-2', 'TGT2'),
        ];
        foreach ([$this->realOne, $realTwo] as $student) {
            $enrollment = Enrollment::create([
                'student_id' => $student->id, 'academic_term_id' => $this->target->id,
                'status' => EnrollmentStatus::Enrolled, 'total_units' => 6, 'submitted_at' => '2026-02-01 09:00:00',
            ]);
            Payment::create(['enrollment_id' => $enrollment->id, 'confirmed_by' => $this->admin->id, 'amount' => '5000.00', 'confirmed_at' => '2026-02-02 09:00:00']);
            foreach ($targetSections as $section) {
                EnrollmentSubject::create(['enrollment_id' => $enrollment->id, 'section_id' => $section->id, 'status' => EnrollmentSubjectStatus::Enrolled]);
                AcademicGrade::create([
                    'student_id' => $student->id, 'subject_id' => $section->subject_id, 'section_id' => $section->id,
                    'academic_term_id' => $this->target->id, 'mark' => GradeMark::Good, 'status' => GradeStatus::Locked, 'encoded_by' => $this->admin->id,
                ]);
            }
        }
        foreach (['ccs', 'coe', 'coa', 'cbae'] as $college) {
            ScheduleProposal::create(['academic_term_id' => $this->target->id, 'college' => $college, 'submitted_by' => $this->admin->id, 'status' => ScheduleProposalStatus::Published]);
        }

        // Produced ahead: the later term's plan, sections, a proposal, enrollments, a queue ticket.
        $aheadPlan = $this->yearOnePlan($this->ahead, $curriculum);
        $aheadSections = [
            $this->blockSection($this->ahead, $aheadPlan, 'AHD-1', 'AHD1'),
            $this->blockSection($this->ahead, $aheadPlan, 'AHD-2', 'AHD2'),
        ];
        ScheduleProposal::create(['academic_term_id' => $this->ahead->id, 'college' => 'ccs', 'submitted_by' => $this->admin->id, 'status' => ScheduleProposalStatus::Published]);

        $this->aheadRealEnrollment = Enrollment::create([
            'student_id' => $this->realThree->id, 'academic_term_id' => $this->ahead->id,
            'status' => EnrollmentStatus::Enrolled, 'total_units' => 6, 'submitted_at' => '2026-10-04 09:00:00',
        ]);
        Payment::create(['enrollment_id' => $this->aheadRealEnrollment->id, 'confirmed_by' => $this->admin->id, 'amount' => '5000.00', 'confirmed_at' => '2026-10-04 10:00:00']);
        EnrollmentSubject::create(['enrollment_id' => $this->aheadRealEnrollment->id, 'section_id' => $aheadSections[0]->id, 'status' => EnrollmentSubjectStatus::Enrolled]);

        // Two test student accounts created after the term was archived.
        $testProfileOne = $this->freshmanProfile('2026-10-00001');
        $testProfileTwo = $this->freshmanProfile('2026-10-00002');
        $this->testUserOne = $testProfileOne->user;
        $this->testUserTwo = $testProfileTwo->user;
        $this->aheadTestEnrollment = Enrollment::create([
            'student_id' => $testProfileOne->id, 'academic_term_id' => $this->ahead->id,
            'status' => EnrollmentStatus::PendingPayment, 'total_units' => 6, 'submitted_at' => '2026-10-06 09:00:00',
        ]);
        EnrollmentSubject::create(['enrollment_id' => $this->aheadTestEnrollment->id, 'section_id' => $aheadSections[1]->id, 'status' => EnrollmentSubjectStatus::Selected]);
        $cycle = QueueCycle::create(['opened_on' => '2026-10-06', 'last_ticket_sequence' => 1, 'last_claimed_on' => '2026-10-06']);
        QueueTicket::create([
            'enrollment_id' => $this->aheadTestEnrollment->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => '2026-10-06', 'status' => QueueTicketStatus::Waiting,
        ]);

        // Every account is "old" except the two test accounts and a super admin created on 2026-10-02.
        DB::table('users')->update(['created_at' => '2026-08-23 10:00:00']);
        DB::table('users')->where('id', $this->testUserOne->id)->update(['created_at' => '2026-10-03 09:00:00']);
        DB::table('users')->where('id', $this->testUserTwo->id)->update(['created_at' => '2026-10-06 09:00:00']);
        $superAdmin = $this->staffUser(UserRole::SuperAdmin, 'super@grc.test');
        DB::table('users')->where('id', $superAdmin->id)->update(['created_at' => '2026-10-02 07:00:00']);

        // Audit rows: some describe only the deleted things, some must stay.
        $this->audit($this->testUserOne, 'enrollment.submitted', 'enrollment', $this->aheadTestEnrollment->id, '2026-10-06 09:00:05');
        $this->audit($this->admin, 'section.created', 'section', $aheadSections[0]->id, '2026-10-03 08:20:00');
        $this->audit($this->admin, 'academic_term.created', 'academic_term', $this->ahead->id, '2026-10-03 08:10:28');
        $this->audit($this->admin, 'academic_term.archived', 'academic_term', $this->target->id, '2026-10-03 08:10:27');
        $this->audit($this->admin, 'academic_term.archived', 'academic_term', $this->target->id, '2026-08-01 09:00:00'); // an earlier cycle: stays
        $this->audit($this->admin, 'section.created', 'section', $targetSections[0]->id, '2026-01-10 09:00:00'); // real history: stays
        $this->audit($this->admin, 'student_profile.provisioned', 'student_profile', $testProfileOne->id, '2026-10-03 09:00:00');
        $this->audit($this->admin, 'student_profile.provisioned', 'student_profile', $this->realOne->id, '2026-08-23 10:00:00'); // real: stays

        $this->testUserOne->createToken('test-one');
        $this->testUserTwo->createToken('test-two');
        $this->admin->createToken('head');
        Notification::create(['user_id' => $this->realOne->user_id, 'type' => NotificationType::EnrollmentPaymentConfirmed, 'message' => 'kept unless --notifications']);
        DB::table('notifications')->where('user_id', $this->realOne->user_id)->update(['created_at' => '2026-10-04 09:00:00']);
    }

    private function audit(User $actor, string $action, string $type, int $id, string $createdAt): void
    {
        $log = AuditLog::create(['actor_user_id' => $actor->id, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id, 'request_id' => 'test']);
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => $createdAt]);
    }

    /** @param list<string> $args */
    private function runScript(array $args = []): array
    {
        ob_start();
        $code = pr_run(pr_opts(['restore', ...$args]));

        return [$code, (string) ob_get_clean()];
    }

    private function targetCounts(): array
    {
        return [
            'sections' => Section::query()->where('academic_term_id', $this->target->id)->count(),
            'enrollments' => Enrollment::query()->where('academic_term_id', $this->target->id)->count(),
            'grades' => AcademicGrade::query()->where('academic_term_id', $this->target->id)->count(),
            'payments' => Payment::query()->whereIn('enrollment_id', Enrollment::query()->where('academic_term_id', $this->target->id)->pluck('id'))->count(),
            'proposals' => ScheduleProposal::query()->where('academic_term_id', $this->target->id)->count(),
        ];
    }

    public function test_a_dry_run_reports_everything_and_writes_nothing(): void
    {
        $before = $this->targetCounts();

        [$code, $output] = $this->runScript();

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('DRY RUN', $output);
        self::assertStringContainsString('rolled back', strtolower($output));
        self::assertStringContainsString('ROWS DELETED PER TABLE', $output);
        self::assertSame('archived', $this->target->fresh()->status->value);
        self::assertSame($this->ahead->id, (int) DB::table('academic_term_current_slots')->value('academic_term_id'));
        self::assertTrue(AcademicTerm::query()->whereKey($this->ahead->id)->exists());
        self::assertTrue(User::query()->whereKey($this->testUserOne->id)->exists());
        self::assertSame($before, $this->targetCounts());
    }

    public function test_apply_makes_the_target_term_current_and_removes_everything_produced_ahead(): void
    {
        $before = $this->targetCounts();
        $realProfiles = StudentProfile::query()->whereIn('student_number', ['2025-06-00001', '2025-06-00002'])->count();

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('COMMITTED', $output);

        // The target term is current again, ongoing, and not archived.
        $target = $this->target->fresh();
        self::assertSame('semester_ongoing', $target->status->value);
        self::assertNull($target->closed_at);
        self::assertNull($target->archived_at);
        self::assertSame([$this->target->id], DB::table('academic_term_current_slots')->pluck('academic_term_id')->map(fn ($id) => (int) $id)->all());

        // Its data is exactly as it was.
        self::assertSame($before, $this->targetCounts());
        self::assertSame($realProfiles, StudentProfile::query()->whereIn('student_number', ['2025-06-00001', '2025-06-00002'])->count());
        self::assertSame(6, DB::table('academic_term_enrollment_windows')->where('academic_term_id', $this->target->id)->where('closes_at', '2026-05-31 17:00:00')->count());

        // Everything ahead is gone, children first.
        self::assertFalse(AcademicTerm::query()->whereKey($this->ahead->id)->exists());
        self::assertSame(0, Section::query()->where('academic_term_id', $this->ahead->id)->count());
        self::assertSame(0, Enrollment::query()->where('academic_term_id', $this->ahead->id)->count());
        self::assertSame(0, ScheduleProposal::query()->where('academic_term_id', $this->ahead->id)->count());
        self::assertSame(0, DB::table('academic_term_enrollment_windows')->where('academic_term_id', $this->ahead->id)->count());
        self::assertSame(0, DB::table('academic_term_college_workflows')->where('academic_term_id', $this->ahead->id)->count());
        self::assertSame(0, QueueTicket::query()->count());
        self::assertSame(2, Payment::query()->count(), 'only the two target-term payments remain');

        // The test accounts, their profiles and tokens are gone; real students, staff and the super admin stay.
        self::assertFalse(User::query()->whereIn('id', [$this->testUserOne->id, $this->testUserTwo->id])->exists());
        self::assertFalse(StudentProfile::query()->whereIn('student_number', ['2026-10-00001', '2026-10-00002'])->exists());
        self::assertTrue(StudentProfile::query()->where('student_number', '2025-06-00001')->exists());
        self::assertTrue(User::query()->where('email', 'super@grc.test')->exists());
        self::assertTrue(User::query()->where('email', 'head@grc.test')->exists());
        self::assertSame(1, DB::table('personal_access_tokens')->count(), 'only the Registrar Head token is left');
    }

    public function test_apply_keeps_the_real_audit_rows_and_removes_only_the_ones_about_deleted_things(): void
    {
        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        $remaining = AuditLog::query()->pluck('action')->all();
        sort($remaining);
        // Kept: the earlier-cycle archive of the target term, the target section's creation, the real student's provisioning.
        self::assertSame(['academic_term.archived', 'section.created', 'student_profile.provisioned'], $remaining);
    }

    public function test_notifications_are_kept_unless_the_flag_is_given(): void
    {
        $this->runScript(['--apply']);
        self::assertSame(1, Notification::query()->count());
    }

    public function test_the_notifications_flag_removes_those_created_since_the_archive(): void
    {
        [$code, $output] = $this->runScript(['--apply', '--notifications']);

        self::assertSame(0, $code, $output);
        self::assertSame(0, Notification::query()->count());
    }

    public function test_it_aborts_and_writes_nothing_when_a_grade_of_the_target_term_is_not_locked(): void
    {
        AcademicGrade::query()->first()->forceFill(['status' => GradeStatus::Submitted])->save();

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(2, $code);
        self::assertStringContainsString('ABORTED', $output);
        self::assertSame('archived', $this->target->fresh()->status->value);
        self::assertTrue(AcademicTerm::query()->whereKey($this->ahead->id)->exists());
        self::assertTrue(User::query()->whereKey($this->testUserOne->id)->exists());
    }

    public function test_it_aborts_when_a_college_has_no_published_schedule(): void
    {
        ScheduleProposal::query()->where('academic_term_id', $this->target->id)->where('college', 'coa')->update(['status' => ScheduleProposalStatus::Closed]);

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(2, $code);
        self::assertStringContainsString('missing: coa', $output);
        self::assertTrue(AcademicTerm::query()->whereKey($this->ahead->id)->exists());
    }

    public function test_it_aborts_when_a_test_account_has_a_real_enrollment_in_the_target_term(): void
    {
        $profile = $this->testUserOne->studentProfile ?? StudentProfile::query()->where('user_id', $this->testUserOne->id)->first();
        Enrollment::create([
            'student_id' => $profile->id, 'academic_term_id' => $this->target->id,
            'status' => EnrollmentStatus::Enrolled, 'total_units' => 3, 'submitted_at' => '2026-02-01 09:00:00',
        ]);

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(2, $code);
        self::assertStringContainsString('no enrollment in the target term or earlier', $output);
        self::assertTrue(User::query()->whereKey($this->testUserOne->id)->exists());
    }

    public function test_it_aborts_when_more_accounts_match_than_the_limit_allows(): void
    {
        [$code, $output] = $this->runScript(['--apply', '--max-accounts=1']);

        self::assertSame(2, $code);
        self::assertStringContainsString('within the limit', $output);
        self::assertTrue(User::query()->whereKey($this->testUserTwo->id)->exists());
    }

    // ------------------------------------------------------------ what the rehearsal changed on REAL rows

    /** @param  array<string, mixed>|null  $before  @param  array<string, mixed>|null  $after */
    private function auditWithValues(string $action, string $type, int $id, string $createdAt, ?array $before, ?array $after): void
    {
        $log = AuditLog::create([
            'actor_user_id' => $this->admin->id, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id,
            'before_values' => $before, 'after_values' => $after, 'request_id' => 'test',
        ]);
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => $createdAt]);
    }

    private function flipRealStudentToIrregular(string $flippedAt = '2026-10-03 08:15:13'): void
    {
        $id = $this->realOne->id;
        $this->auditWithValues('student_profile.enrollment_category_reclassified', 'student_profile', $id, '2026-08-24 06:07:30', ['enrollment_category' => null], ['enrollment_category' => 'regular', 'reasons' => []]);
        // The rehearsal's flip (written twice in the same second, as on production).
        foreach ([1, 2] as $ignored) {
            $this->auditWithValues('student_profile.enrollment_category_reclassified', 'student_profile', $id, $flippedAt, ['enrollment_category' => 'regular'], ['enrollment_category' => 'irregular', 'reasons' => [['code' => 'needs_adding_backlog', 'message' => 'x']]]);
        }
        DB::table('student_profiles')->where('id', $id)->update(['enrollment_category' => 'irregular', 'enrollment_category_derived_at' => $flippedAt]);
    }

    public function test_a_flip_a_few_minutes_before_the_archive_needs_the_since_option_to_be_put_back(): void
    {
        // On production the flip happened at 13:15:13 and the archive at 13:18:27: before the default lookback (archive - 2 minutes).
        $this->flipRealStudentToIrregular('2026-10-03 08:05:00');

        $this->runScript(['--apply']);
        self::assertSame('irregular', DB::table('student_profiles')->where('id', $this->realOne->id)->value('enrollment_category'), 'the default lookback does not reach it');
    }

    public function test_the_since_option_widens_the_lookback_for_flips_queue_cycles_and_notifications(): void
    {
        $this->flipRealStudentToIrregular('2026-10-03 08:05:00');
        Notification::create(['user_id' => $this->realOne->user_id, 'type' => NotificationType::EnrollmentPaymentConfirmed, 'message' => 'rehearsal']);
        DB::table('notifications')->where('message', 'rehearsal')->update(['created_at' => '2026-10-03 08:06:00']);

        [$code, $output] = $this->runScript(['--apply', '--notifications', '--since=2026-10-03T08:00:00']);

        self::assertSame(0, $code, $output);
        self::assertSame('regular', DB::table('student_profiles')->where('id', $this->realOne->id)->value('enrollment_category'));
        self::assertSame(0, Notification::query()->where('message', 'rehearsal')->count());
    }

    public function test_a_real_student_flipped_to_irregular_since_the_archive_is_put_back_from_the_audit_trail(): void
    {
        $this->flipRealStudentToIrregular();

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        $row = DB::table('student_profiles')->where('id', $this->realOne->id)->first();
        self::assertSame('regular', $row->enrollment_category);
        self::assertSame('2026-08-24 06:07:30', $row->enrollment_category_derived_at, 'derived_at goes back to the earlier derivation');
        self::assertSame(1, AuditLog::query()->where('action', 'student_profile.enrollment_category_reclassified')->where('auditable_id', $this->realOne->id)->count(), 'only the earlier derivation stays in the audit trail');
        self::assertStringContainsString('student #'.$this->realOne->id, $output);
    }

    public function test_a_student_whose_only_reclassification_is_older_than_the_archive_is_left_alone(): void
    {
        $id = $this->realOne->id;
        $this->auditWithValues('student_profile.enrollment_category_reclassified', 'student_profile', $id, '2026-08-24 06:07:30', ['enrollment_category' => 'regular'], ['enrollment_category' => 'irregular', 'reasons' => []]);
        DB::table('student_profiles')->where('id', $id)->update(['enrollment_category' => 'irregular', 'enrollment_category_derived_at' => '2026-08-24 06:07:30']);

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        self::assertSame('irregular', DB::table('student_profiles')->where('id', $id)->value('enrollment_category'));
        self::assertSame(1, AuditLog::query()->where('action', 'student_profile.enrollment_category_reclassified')->count());
    }

    public function test_it_aborts_when_more_students_were_flipped_than_the_limit_allows(): void
    {
        $this->flipRealStudentToIrregular();

        [$code, $output] = $this->runScript(['--apply', '--max-category-reverts=0']);

        self::assertSame(2, $code);
        self::assertStringContainsString('category flips', $output);
        self::assertSame('irregular', DB::table('student_profiles')->where('id', $this->realOne->id)->value('enrollment_category'));
        self::assertSame('archived', $this->target->fresh()->status->value);
    }

    public function test_the_student_number_counter_gives_back_the_numbers_the_deleted_test_accounts_took(): void
    {
        // The two test accounts hold 2026-10-00001 and -00002, the top of the 2026 counter; the 2025 counter belongs to real students.
        DB::table('student_number_sequences')->insert([
            ['year' => 2026, 'last_value' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['year' => 2025, 'last_value' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        self::assertSame(0, (int) DB::table('student_number_sequences')->where('year', 2026)->value('last_value'));
        self::assertSame(2, (int) DB::table('student_number_sequences')->where('year', 2025)->value('last_value'), 'real students keep their numbers');
    }

    public function test_the_student_number_counter_is_left_alone_when_the_deleted_numbers_are_not_at_its_top(): void
    {
        DB::table('student_number_sequences')->insert(['year' => 2026, 'last_value' => 7, 'created_at' => now(), 'updated_at' => now()]);

        $this->runScript(['--apply']);

        self::assertSame(7, (int) DB::table('student_number_sequences')->where('year', 2026)->value('last_value'));
    }

    public function test_reclaiming_a_deleted_top_number_stops_at_an_unrelated_gap_and_is_repeatable(): void
    {
        StudentProfile::query()->where('user_id', $this->testUserOne->id)->update(['student_number' => '2026-10-00004']);
        StudentProfile::query()->where('user_id', $this->testUserTwo->id)->update(['student_number' => '2026-10-00006']);
        DB::table('student_number_sequences')->insert(['year' => 2026, 'last_value' => 6, 'created_at' => now(), 'updated_at' => now()]);

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        self::assertSame(5, (int) DB::table('student_number_sequences')->where('year', 2026)->value('last_value'));

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        self::assertSame(5, (int) DB::table('student_number_sequences')->where('year', 2026)->value('last_value'));
    }

    public function test_a_surviving_student_with_the_same_yearly_suffix_keeps_the_counter(): void
    {
        $this->realOne->update(['student_number' => '2026-01-00002']);
        DB::table('student_number_sequences')->insert(['year' => 2026, 'last_value' => 2, 'created_at' => now(), 'updated_at' => now()]);

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        self::assertSame(2, (int) DB::table('student_number_sequences')->where('year', 2026)->value('last_value'));
        self::assertSame('2026-01-00002', $this->realOne->fresh()->student_number);
    }

    public function test_student_number_reclamation_leaves_unrelated_years_and_dry_runs_unchanged(): void
    {
        DB::table('student_number_sequences')->insert([
            ['year' => 2026, 'last_value' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['year' => 2027, 'last_value' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        [$code, $output] = $this->runScript();

        self::assertSame(0, $code, $output);
        self::assertSame(2, (int) DB::table('student_number_sequences')->where('year', 2026)->value('last_value'));
        self::assertSame(3, (int) DB::table('student_number_sequences')->where('year', 2027)->value('last_value'));

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        self::assertSame(0, (int) DB::table('student_number_sequences')->where('year', 2026)->value('last_value'));
        self::assertSame(3, (int) DB::table('student_number_sequences')->where('year', 2027)->value('last_value'));
    }

    private function queueCycle(string $created, ?string $closed, int $sequence = 1): int
    {
        return (int) DB::table('queue_cycles')->insertGetId([
            'opened_on' => substr($created, 0, 10), 'last_claimed_on' => substr($created, 0, 10), 'last_ticket_sequence' => $sequence,
            'closed_at' => $closed, 'created_at' => $created, 'updated_at' => $created,
        ]);
    }

    public function test_queue_cycles_opened_by_the_rehearsal_are_removed_and_the_cycle_it_closed_is_open_again(): void
    {
        // The target term was archived on 2026-10-03 08:10:27. A cycle that was open, then closed by the rehearsal's first claim:
        $ancient = $this->queueCycle('2026-09-15 09:00:00', '2026-09-16 10:00:00');
        $closedByRehearsal = $this->queueCycle('2026-10-01 09:00:00', '2026-10-06 09:00:00', 3);
        $this->auditWithValues('queue_cycle.closed', 'queue_cycle', $ancient, '2026-09-16 10:00:00', ['closed_at' => null], ['closed_at' => '2026-09-16T10:00:00Z']);
        $this->auditWithValues('queue_cycle.closed', 'queue_cycle', $closedByRehearsal, '2026-10-06 09:00:00', ['closed_at' => null], ['closed_at' => '2026-10-06T09:00:00Z']);
        $rehearsalCycle = (int) QueueCycle::query()->whereNull('closed_at')->value('id'); // opened in setUp for the test ticket

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        self::assertFalse(DB::table('queue_cycles')->where('id', $rehearsalCycle)->exists(), 'the rehearsal cycle is empty and gone');
        self::assertNull(DB::table('queue_cycles')->where('id', $closedByRehearsal)->value('closed_at'), 'the cycle the rehearsal closed is open again');
        self::assertNotNull(DB::table('queue_cycles')->where('id', $ancient)->value('closed_at'), 'an old closed cycle stays closed');
        self::assertSame(1, DB::table('queue_cycles')->whereNull('closed_at')->count());
        self::assertSame([$ancient], AuditLog::query()->where('action', 'queue_cycle.closed')->pluck('auditable_id')->map(fn ($v) => (int) $v)->all(), 'only the old close stays in the audit trail');
    }

    public function test_queue_cycles_with_tickets_that_stay_are_never_removed(): void
    {
        // A cycle created after the archive that still holds a ticket of a REAL student of the target term.
        $enrollment = Enrollment::query()->where('academic_term_id', $this->target->id)->first();
        $cycle = $this->queueCycle('2026-10-05 09:00:00', '2026-10-06 10:00:00', 1); // closed: the setUp cycle is the one open cycle allowed
        QueueTicket::create([
            'enrollment_id' => $enrollment->id, 'queue_cycle_id' => $cycle, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => '2026-10-05', 'status' => QueueTicketStatus::Waiting,
        ]);

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        self::assertTrue(DB::table('queue_cycles')->where('id', $cycle)->exists());
        self::assertSame(1, QueueTicket::query()->count());
    }

    public function test_running_it_twice_is_harmless(): void
    {
        $this->runScript(['--apply']);
        $first = $this->targetCounts();

        [$code, $output] = $this->runScript(['--apply']);

        self::assertSame(0, $code, $output);
        self::assertSame($first, $this->targetCounts());
        self::assertSame('semester_ongoing', $this->target->fresh()->status->value);
    }
}
