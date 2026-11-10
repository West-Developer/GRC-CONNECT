<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Mail\EnrollmentConfirmedMail;
use App\Mail\FreshmanSectionAvailableMail;
use App\Mail\StudentAccountSetupMail;
use App\Models\AccountPayment;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\ScheduleProposal;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsBlockSections;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * A freshman pays while every section is full, a schedule is published that opens one, the
 * freshman enrolls in it and Registrar Staff approve — the whole waiting path through the
 * real endpoints (ADR 0043). Nothing is paid twice and nobody queues again.
 */
final class FreshmanPayFirstJourneyTest extends TestCase
{
    use BuildsBlockSections;
    use BuildsFreshmen;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_the_waiting_freshman_is_notified_when_a_schedule_is_published_and_is_enrolled_by_the_registrar_approval(): void
    {
        Mail::fake();
        $profile = $this->freshmanProfile();
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $this->fullBlock($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        // 1. The Cashier takes the payment: every block is full, so it is held and the account is set up.
        $cashierToken = $this->tokenFor(UserRole::AccountingStaff, 'cashier@grc.test');
        $this->withToken($cashierToken)
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00'])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_section');
        Mail::assertSent(StudentAccountSetupMail::class);
        self::assertSame(1, AccountPayment::query()->whereNull('enrollment_id')->count());

        // 2. A new block is published through the real publish transition.
        $planned = $this->blockSection($term, $plan, 'IT102', 'ITP2', ['status' => 'planned']);
        $chair = $this->staffUser(UserRole::ProgramChair, 'chair@grc.test');
        $proposal = ScheduleProposal::create(['academic_term_id' => $term->id, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::DeanApproved]);
        $executiveToken = $this->tokenFor(UserRole::ExecutiveDirector, 'executive@grc.test');
        $this->app['auth']->forgetGuards();
        $this->withToken($executiveToken)
            ->patchJson("/api/v1/schedule-proposals/{$proposal->id}", ['action' => 'publish'])
            ->assertOk();

        self::assertSame(1, Notification::query()
            ->where('user_id', $profile->user_id)
            ->where('type', NotificationType::FreshmanSectionAvailable)
            ->count());
        Mail::assertSent(FreshmanSectionAvailableMail::class, fn (FreshmanSectionAvailableMail $mail): bool => $mail->hasTo($profile->user->email));

        // 3. The freshman (their account is now usable) enrolls in the section that opened.
        $profile->user->forceFill(['status' => UserStatus::Active, 'account_setup_completed_at' => now(), 'last_otp_verified_at' => now()])->save();
        $studentToken = $this->loginToken($profile->user->email);
        $this->app['auth']->forgetGuards();
        $submitted = $this->withToken($studentToken)
            ->postJson('/api/v1/enrollments', ['academic_term_id' => $term->id, 'block_code' => 'IT102'])
            ->assertCreated();
        $enrollmentId = (int) $submitted->json('data.id');
        self::assertSame(EnrollmentStatus::PendingRegistrarApproval, Enrollment::query()->findOrFail($enrollmentId)->status);

        // 4. Registrar Staff approve it: the held payment completes the enrollment.
        $registrarToken = $this->tokenFor(UserRole::RegistrarStaff, 'registrar.staff@grc.test');
        $this->app['auth']->forgetGuards();
        $this->withToken($registrarToken)
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'registrar_approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'enrolled');

        $enrollment = Enrollment::query()->findOrFail($enrollmentId);
        self::assertSame(EnrollmentStatus::Enrolled, $enrollment->status);
        self::assertSame(1, Payment::query()->where('enrollment_id', $enrollmentId)->count());
        self::assertSame($enrollmentId, AccountPayment::query()->sole()->enrollment_id);
        Mail::assertSent(EnrollmentConfirmedMail::class, fn (EnrollmentConfirmedMail $mail): bool => $mail->hasTo($profile->user->email));
        self::assertSame(1, $planned->fresh()->enrolled_count);
        self::assertSame(1, Section::query()->where('section_code', 'IT102')->first()->enrolled_count);
    }

    public function test_a_freshman_who_pays_into_an_open_block_is_enrolled_only_when_the_registrar_approves(): void
    {
        Mail::fake();
        $profile = $this->freshmanProfile();
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $section = $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        // 1. The Cashier takes the payment while a block is open: submitted, but not enrolled and not emailed.
        $this->withToken($this->tokenFor(UserRole::AccountingStaff, 'cashier@grc.test'))
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00'])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_registrar_approval')
            ->assertJsonPath('data.enrollment.status', 'pending_registrar_approval');

        $enrollment = Enrollment::query()->sole();
        self::assertSame(EnrollmentStatus::PendingRegistrarApproval, $enrollment->status);
        self::assertSame(0, EnrollmentDocument::query()->count());
        Mail::assertNotSent(EnrollmentConfirmedMail::class);

        // 2. Registrar Staff approve: the held payment completes the enrollment, and the enrolled email goes out once.
        $registrarToken = $this->tokenFor(UserRole::RegistrarStaff, 'registrar.staff@grc.test');
        $this->app['auth']->forgetGuards();
        $this->withToken($registrarToken)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'enrolled');

        self::assertSame(EnrollmentStatus::Enrolled, $enrollment->fresh()->status);
        self::assertSame(1, EnrollmentDocument::query()->where('enrollment_id', $enrollment->id)->count());
        self::assertSame(1, Payment::query()->where('enrollment_id', $enrollment->id)->count());
        self::assertSame($enrollment->id, AccountPayment::query()->sole()->enrollment_id);
        self::assertSame(1, $section->fresh()->enrolled_count);
        Mail::assertSent(EnrollmentConfirmedMail::class, 1);
    }

    private function loginToken(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::FRESHMAN_TEST_PASSWORD])->json('data.token');
    }

    private function tokenFor(UserRole $role, string $email): string
    {
        User::query()->where('email', $email)->first() ?? User::create([
            'name' => 'Test '.$role->value, 'email' => $email, 'password' => self::PASSWORD,
            'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return $this->loginToken($email);
    }
}
