<?php

namespace Tests\Feature\Actions\Academic;

use App\Actions\Academic\IssueTransfereeQueueTicket;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\QueueLinkOutbox;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * A transferee gets their queue number the moment their credit mapping has nothing
 * left with the Program Chair (ADR 0043, amended 2026-10-05 and again 2026-10-15): no
 * row `pending`, at least one submitted row. A row the Chair passed to the Registrar Head
 * (`endorsed`) no longer holds the number back. The ticket belongs to the student profile,
 * like a freshman's, and is issued once.
 */
final class IssueTransfereeQueueTicketTest extends TestCase
{
    use BuildsFreshmen;
    use RefreshDatabase;

    private int $sequence = 0;

    private function transferee(StudentType $type = StudentType::Transferee): StudentProfile
    {
        $this->sequence++;

        return $this->freshmanProfile(sprintf('2026-09-%04d', $this->sequence), $type);
    }

    private function credit(StudentProfile $profile, TransfereeCreditStatus $status): TransfereeCredit
    {
        return TransfereeCredit::create([
            'student_id' => $profile->id, 'source_institution' => 'Other University',
            'source_subject_code' => 'X'.$status->value, 'source_subject_title' => 'Subject '.$status->value,
            'source_grade' => '2.00', 'credited_units' => 3, 'status' => $status,
        ]);
    }

    private function whenClosed(StudentProfile $profile, ?QueueLinkOutbox $outbox = null): mixed
    {
        return $this->app->make(IssueTransfereeQueueTicket::class)->whenMappingCloses(
            $profile,
            $this->staffUser(UserRole::RegistrarHead, 'head@grc.test'),
            new AuditRequestContext('test-close', null),
            $outbox,
        );
    }

    public function test_it_issues_one_profile_linked_ticket_with_a_token_and_tells_the_student_when_every_credit_is_closed(): void
    {
        $profile = $this->transferee();
        $this->credit($profile, TransfereeCreditStatus::Approved);
        $this->credit($profile, TransfereeCreditStatus::Rejected);
        $outbox = new QueueLinkOutbox;

        $issued = $this->whenClosed($profile, $outbox);

        self::assertNotNull($issued);
        self::assertNotNull($issued->plainToken);
        $ticket = QueueTicket::query()->sole();
        self::assertSame($profile->id, $ticket->student_profile_id);
        self::assertNull($ticket->enrollment_id);
        self::assertSame('Q001', $ticket->ticket_number);
        self::assertSame($ticket->id, $issued->ticket->id);
        self::assertSame(QueueTicket::hashPublicToken($issued->plainToken), $ticket->public_token_hash);

        $notice = Notification::query()->where('user_id', $profile->user_id)->where('type', NotificationType::TransfereeQueueNumberIssued)->sole();
        self::assertStringContainsString('Q001', $notice->message);
        self::assertSame([$issued], $outbox->drain());
        self::assertSame('transferee_credit_mapping_closed', AuditLog::query()->where('auditable_id', $ticket->id)->latest('id')->first()->after_values['source']);
    }

    public function test_it_does_nothing_while_any_credit_is_still_with_the_program_chair(): void
    {
        $profile = $this->transferee();
        $this->credit($profile, TransfereeCreditStatus::Approved);
        $this->credit($profile, TransfereeCreditStatus::Endorsed);
        $this->credit($profile, TransfereeCreditStatus::Pending);
        $outbox = new QueueLinkOutbox;

        self::assertNull($this->whenClosed($profile, $outbox));
        self::assertSame([], $outbox->drain());
        self::assertSame(0, QueueTicket::query()->where('student_profile_id', $profile->id)->count());
    }

    public function test_credits_the_chair_passed_to_the_registrar_head_are_enough_for_the_number(): void
    {
        $profile = $this->transferee();
        $this->credit($profile, TransfereeCreditStatus::Endorsed);

        $issued = $this->whenClosed($profile, new QueueLinkOutbox);

        self::assertNotNull($issued);
        self::assertSame(1, QueueTicket::query()->where('student_profile_id', $profile->id)->count());
        $notice = Notification::query()->where('user_id', $profile->user_id)->where('type', NotificationType::TransfereeQueueNumberIssued)->sole();
        self::assertStringContainsString('Registrar Head', $notice->message);
    }

    public function test_it_does_nothing_for_a_transferee_with_no_submitted_credit_even_with_drafts(): void
    {
        $none = $this->transferee();
        $drafts = $this->transferee();
        $this->credit($drafts, TransfereeCreditStatus::Draft);

        self::assertNull($this->whenClosed($none));
        self::assertNull($this->whenClosed($drafts));
        self::assertSame(0, QueueTicket::query()->count());
    }

    public function test_a_second_call_issues_nothing_and_adds_nothing_to_the_outbox(): void
    {
        $profile = $this->transferee();
        $this->credit($profile, TransfereeCreditStatus::Approved);
        $this->whenClosed($profile, new QueueLinkOutbox);
        $outbox = new QueueLinkOutbox;

        self::assertNull($this->whenClosed($profile, $outbox));

        self::assertSame([], $outbox->drain());
        self::assertSame(1, QueueTicket::query()->count());
        self::assertSame(1, Notification::query()->where('type', NotificationType::TransfereeQueueNumberIssued)->count());
    }

    public function test_it_does_nothing_for_other_student_types_or_a_transferee_who_already_paid(): void
    {
        foreach ([StudentType::Freshman, StudentType::Returnee, StudentType::ExistingStudent] as $type) {
            $profile = $this->transferee($type);
            $this->credit($profile, TransfereeCreditStatus::Approved);

            self::assertNull($this->whenClosed($profile), $type->value);
        }

        $paid = $this->transferee();
        $this->credit($paid, TransfereeCreditStatus::Approved);
        $paid->forceFill(['freshman_payment_confirmed_at' => now()])->save();

        self::assertNull($this->whenClosed($paid));
        self::assertSame(0, QueueTicket::query()->count());
    }

    public function test_a_transferee_whose_every_credit_was_rejected_still_gets_a_number(): void
    {
        $profile = $this->transferee();
        $this->credit($profile, TransfereeCreditStatus::Rejected);

        self::assertNotNull($this->whenClosed($profile));
        self::assertSame(1, QueueTicket::query()->where('student_profile_id', $profile->id)->count());
    }

    public function test_tickets_draw_from_the_one_shared_cycle_sequence(): void
    {
        $first = $this->transferee();
        $second = $this->transferee();
        $this->credit($first, TransfereeCreditStatus::Approved);
        $this->credit($second, TransfereeCreditStatus::Approved);

        $this->whenClosed($first);
        $this->whenClosed($second);

        self::assertSame(['Q001', 'Q002'], QueueTicket::query()->orderBy('ticket_sequence')->pluck('ticket_number')->all());
        self::assertSame(1, QueueTicket::query()->distinct('queue_cycle_id')->count('queue_cycle_id'));
    }
}
