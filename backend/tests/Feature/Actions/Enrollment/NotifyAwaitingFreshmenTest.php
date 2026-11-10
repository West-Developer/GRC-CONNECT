<?php

namespace Tests\Feature\Actions\Enrollment;

use App\Actions\Enrollment\ConfirmFreshmanPayment;
use App\Actions\Enrollment\NotifyAwaitingFreshmen;
use App\Actions\Scheduling\UpdateSection;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\FreshmanPaymentResult;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Mail\FreshmanSectionAvailableMail;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\Notification;
use App\Models\Section;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsBlockSections;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * A freshman whose payment is held because every section was full is told when a
 * section opens (ADR 0043): when a section is published, or when a published
 * section gets more seats. Only freshmen of that program and year who paid and are
 * not enrolled yet.
 */
final class NotifyAwaitingFreshmenTest extends TestCase
{
    use BuildsBlockSections;
    use BuildsFreshmen;
    use RefreshDatabase;

    public function test_raising_the_capacity_of_a_full_section_tells_a_waiting_freshman(): void
    {
        Mail::fake();
        [$waiting, $full] = $this->waitingFreshmanAndFullSection();

        $this->raiseCapacity($full, 40);

        self::assertSame(1, Notification::query()
            ->where('user_id', $waiting->user_id)
            ->where('type', NotificationType::FreshmanSectionAvailable)
            ->count());
        Mail::assertSent(FreshmanSectionAvailableMail::class, fn (FreshmanSectionAvailableMail $mail): bool => $mail->hasTo($waiting->user->email));
    }

    public function test_publishing_a_new_section_tells_a_waiting_freshman(): void
    {
        Mail::fake();
        [$waiting, , $term, $plan] = $this->waitingFreshmanAndFullSection();
        $planned = $this->blockSection($term, $plan, 'IT102', 'ITP2', ['status' => 'planned']);

        $planned->forceFill(['status' => 'published'])->save();
        $this->app->make(NotifyAwaitingFreshmen::class)->forSections([$planned->id]);

        self::assertSame(1, Notification::query()
            ->where('user_id', $waiting->user_id)
            ->where('type', NotificationType::FreshmanSectionAvailable)
            ->count());
    }

    public function test_a_freshman_is_told_once_even_when_several_sections_open_together(): void
    {
        Mail::fake();
        [$waiting, , $term, $plan] = $this->waitingFreshmanAndFullSection();
        $a = $this->blockSection($term, $plan, 'IT102', 'ITP2');
        $b = $this->blockSection($term, $plan, 'IT102', 'ITP2B');

        $this->app->make(NotifyAwaitingFreshmen::class)->forSections([$a->id, $b->id]);

        self::assertSame(1, Notification::query()->where('user_id', $waiting->user_id)->where('type', NotificationType::FreshmanSectionAvailable)->count());
    }

    public function test_lowering_the_capacity_or_changing_something_else_tells_nobody(): void
    {
        Mail::fake();
        [$waiting, , , , $open] = $this->waitingFreshmanAndFullSection(withOpenSection: true);

        $this->raiseCapacity($open, 30); // 40 -> 30: fewer seats

        self::assertSame(0, Notification::query()->where('user_id', $waiting->user_id)->where('type', NotificationType::FreshmanSectionAvailable)->count());
    }

    public function test_a_freshman_who_has_not_paid_or_is_already_enrolled_or_is_in_another_program_is_not_told(): void
    {
        Mail::fake();
        [$waiting, $full, $term, $plan] = $this->waitingFreshmanAndFullSection();
        $unpaid = $this->freshmanProfile('2026-10-00020');
        $transferee = $this->freshmanProfile('2026-10-00021', StudentType::Transferee);
        $transferee->forceFill(['freshman_payment_confirmed_at' => now()])->save();
        $otherCurriculum = Curriculum::create([
            'program_id' => $waiting->program_id, 'name' => 'Other curriculum',
            'effective_school_year' => '2027-2028', 'status' => CurriculumStatus::Active,
        ]);
        $elsewhere = $this->freshmanProfile('2026-10-00022');
        $elsewhere->forceFill(['curriculum_id' => $otherCurriculum->id, 'freshman_payment_confirmed_at' => now()])->save();

        $this->raiseCapacity($full, 40);

        foreach ([$unpaid, $transferee, $elsewhere] as $notTold) {
            self::assertSame(0, Notification::query()->where('user_id', $notTold->user_id)->where('type', NotificationType::FreshmanSectionAvailable)->count());
        }
        self::assertSame(1, Notification::query()->where('user_id', $waiting->user_id)->where('type', NotificationType::FreshmanSectionAvailable)->count());
    }

    public function test_an_irregular_freshman_is_not_told_about_a_block_they_cannot_take(): void
    {
        Mail::fake();
        [$waiting, $full] = $this->waitingFreshmanAndFullSection();
        // Irregular freshmen enroll subject by subject, so a new block is not what they wait for.
        $irregular = $this->freshmanProfile('2026-10-00024', category: 'irregular');
        $irregular->forceFill(['freshman_payment_confirmed_at' => now()])->save();

        $this->raiseCapacity($full, 40);

        self::assertSame(0, Notification::query()->where('user_id', $irregular->user_id)->where('type', NotificationType::FreshmanSectionAvailable)->count());
        self::assertSame(1, Notification::query()->where('user_id', $waiting->user_id)->where('type', NotificationType::FreshmanSectionAvailable)->count());
    }

    public function test_a_failure_finding_the_waiting_freshmen_never_stops_the_section_change(): void
    {
        Mail::fake();
        Exceptions::fake();
        [, $full] = $this->waitingFreshmanAndFullSection();
        // Whatever goes wrong while looking for the people to tell (here: reading a profile) must not
        // reach the Chair or the Executive Director who is publishing a schedule.
        Event::listen('eloquent.retrieved: '.StudentProfile::class, function (): void {
            throw new \RuntimeException('simulated failure while finding the waiting freshmen');
        });

        $this->raiseCapacity($full, 40);

        self::assertSame(40, $full->fresh()->capacity);
        Exceptions::assertReported(fn (\RuntimeException $exception): bool => str_contains($exception->getMessage(), 'simulated failure'));
        self::assertSame(0, Notification::query()->where('type', NotificationType::FreshmanSectionAvailable)->count());
    }

    public function test_a_failing_email_never_blocks_the_section_change(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        [$waiting, $full] = $this->waitingFreshmanAndFullSection();

        $this->raiseCapacity($full, 40);

        self::assertSame(40, $full->fresh()->capacity);
        self::assertSame(1, Notification::query()->where('user_id', $waiting->user_id)->where('type', NotificationType::FreshmanSectionAvailable)->count());
    }

    /**
     * @return array{0: StudentProfile, 1: Section, 2: AcademicTerm, 3: AcademicTermSectionPlan, 4: ?Section}
     */
    private function waitingFreshmanAndFullSection(bool $withOpenSection = false): array
    {
        $profile = $this->freshmanProfile('2026-10-00001');
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $full = $this->fullBlock($term, $plan, 'IT101', 'ITP1');
        $open = $withOpenSection ? $this->blockSection($term, $plan, 'IT102', 'ITP2') : null;
        $this->issueFreshmanTicket($profile);

        if ($withOpenSection) {
            // The freshman paid while only IT101 existed (full); IT102 appeared afterwards.
            $open->forceFill(['status' => 'planned'])->save();
        }
        $result = $this->app->make(ConfirmFreshmanPayment::class)->execute(
            $profile->fresh(),
            ['amount' => '1000.00'],
            $this->staffUser(UserRole::AccountingStaff, 'cashier@grc.test'),
            new AuditRequestContext('test', null),
        );
        self::assertSame(FreshmanPaymentResult::AWAITING_SECTION, $result->outcome);
        if ($withOpenSection) {
            $open->forceFill(['status' => 'published'])->save();
        }

        return [$profile->fresh(), $full, $term, $plan, $open];
    }

    private function raiseCapacity(Section $section, int $capacity): void
    {
        // Columns with a database default (like `capacity_source`) are only on the model once it is re-read.
        $section = $section->fresh();

        $this->app->make(UpdateSection::class)->execute(
            $this->staffUser(UserRole::ProgramChair, 'chair@grc.test'),
            [
                'academic_term_id' => $section->academic_term_id,
                'subject_id' => $section->subject_id,
                'section_code' => $section->section_code,
                'professor_id' => $section->professor_id,
                'schedule_days' => $section->schedule_days,
                'starts_at_time' => $section->starts_at_time,
                'ends_at_time' => $section->ends_at_time,
                'room' => $section->room,
                'modality' => $section->modality?->value,
                'capacity' => $capacity,
                'viability_threshold' => $section->viability_threshold,
                'status' => $section->status->value,
            ],
            $section,
            new AuditRequestContext('test-update-section', null),
        );
    }
}
