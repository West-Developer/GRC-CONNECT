<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Enrollment\RotateFreshmanQueueToken;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\QueueTicketPriority;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * The link a freshman is emailed opens a live view of the payment queue without
 * signing in. It shows the freshman's own number and the board (now serving and the
 * next waiting numbers) — ticket numbers only, never a name or a student number.
 */
final class PublicQueueViewEndpointTest extends TestCase
{
    use BuildsFreshmen;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('public-queue-view');
    }

    public function test_the_link_shows_the_freshmans_own_ticket_without_signing_in(): void
    {
        $issued = $this->issueFreshmanTicket($this->freshmanProfile());

        $this->getJson('/api/v1/public/queue-view/'.$issued->plainToken)
            ->assertOk()
            ->assertJsonPath('data.type', 'student_queue_view')
            ->assertJsonPath('data.stage', 'freshman_awaiting_payment')
            ->assertJsonPath('data.can_claim', false)
            ->assertJsonPath('data.ticket.ticket_number', 'Q001')
            ->assertJsonPath('data.ticket.status', 'waiting')
            ->assertJsonPath('data.ticket.position', 0)
            ->assertJsonPath('data.now_serving_ticket_number', null)
            ->assertJsonPath('data.cut_off_today', false);
    }

    public function test_it_shows_who_is_being_called_and_who_is_next_by_number_only(): void
    {
        $first = $this->issueFreshmanTicket($this->freshmanProfile('2026-10-00001'));
        $second = $this->issueFreshmanTicket($this->freshmanProfile('2026-10-00002'));
        $third = $this->issueFreshmanTicket($this->freshmanProfile('2026-10-00003'));
        $first->ticket->forceFill(['status' => QueueTicketStatus::Serving])->save();

        $response = $this->getJson('/api/v1/public/queue-view/'.$third->plainToken)->assertOk();

        $response->assertJsonPath('data.now_serving_ticket_number', 'Q001')
            ->assertJsonPath('data.upcoming_ticket_numbers', ['Q002', 'Q003'])
            ->assertJsonPath('data.ticket.ticket_number', 'Q003')
            // One waiting ahead of Q003 (Q002); Q001 is already being served.
            ->assertJsonPath('data.ticket.position', 1);
        self::assertNotNull($second->plainToken);
    }

    public function test_a_priority_ticket_is_ahead_in_the_position_and_the_board(): void
    {
        $first = $this->issueFreshmanTicket($this->freshmanProfile('2026-10-00001'));
        $second = $this->issueFreshmanTicket($this->freshmanProfile('2026-10-00002'));
        $second->ticket->forceFill(['priority' => QueueTicketPriority::Priority])->save();

        $this->getJson('/api/v1/public/queue-view/'.$first->plainToken)
            ->assertOk()
            ->assertJsonPath('data.upcoming_ticket_numbers', ['Q002', 'Q001'])
            ->assertJsonPath('data.ticket.position', 1);
    }

    public function test_the_response_never_contains_a_name_an_email_or_a_student_number(): void
    {
        $issued = $this->issueFreshmanTicket($this->freshmanProfile('2026-10-00007'));
        $this->issueFreshmanTicket($this->freshmanProfile('2026-10-00008'));

        $body = $this->getJson('/api/v1/public/queue-view/'.$issued->plainToken)->assertOk()->getContent();

        self::assertStringNotContainsString('2026-10-00007', (string) $body);
        self::assertStringNotContainsString('2026-10-00008', (string) $body);
        self::assertStringNotContainsString('Fresh Student', (string) $body);
        self::assertStringNotContainsString('@grc.test', (string) $body);
    }

    public function test_the_serving_ticket_reports_how_many_times_it_was_called_out(): void
    {
        $issued = $this->issueFreshmanTicket($this->freshmanProfile());
        $issued->ticket->forceFill(['status' => QueueTicketStatus::Serving, 'announce_count' => 3])->save();

        $this->getJson('/api/v1/public/queue-view/'.$issued->plainToken)
            ->assertOk()
            ->assertJsonPath('data.ticket.status', 'serving')
            ->assertJsonPath('data.ticket.announce_count', 3)
            ->assertJsonPath('data.ticket.position', null);
    }

    public function test_an_unknown_or_malformed_token_is_not_found_and_gives_nothing_away(): void
    {
        $this->issueFreshmanTicket($this->freshmanProfile());

        $this->getJson('/api/v1/public/queue-view/'.str_repeat('a', 48))->assertNotFound();
        $this->getJson('/api/v1/public/queue-view/short')->assertNotFound();
        $this->getJson('/api/v1/public/queue-view/'.str_repeat('a', 300))->assertNotFound();
    }

    public function test_a_kiosk_ticket_has_no_public_link(): void
    {
        // A ticket that belongs to an enrollment carries no token hash at all.
        $cycle = QueueCycle::create(['opened_on' => now()->toDateString(), 'last_ticket_sequence' => 1]);
        $profile = $this->freshmanProfile();
        QueueTicket::create([
            'student_profile_id' => $profile->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => now()->toDateString(), 'status' => QueueTicketStatus::Waiting,
        ]);

        $this->getJson('/api/v1/public/queue-view/'.str_repeat('0', 48))->assertNotFound();
    }

    public function test_after_the_first_payment_the_link_says_so(): void
    {
        $profile = $this->freshmanProfile();
        $issued = $this->issueFreshmanTicket($profile);
        $profile->forceFill(['freshman_payment_confirmed_at' => now()])->save();
        $issued->ticket->forceFill(['status' => QueueTicketStatus::Served, 'served_at' => now()])->save();

        $this->getJson('/api/v1/public/queue-view/'.$issued->plainToken)
            ->assertOk()
            ->assertJsonPath('data.stage', 'freshman_paid')
            ->assertJsonPath('data.ticket.status', 'served');
    }

    public function test_a_transferees_link_has_its_own_stages_before_and_after_the_first_payment(): void
    {
        $profile = $this->freshmanProfile('2026-0900', StudentType::Transferee);
        $issued = $this->issueFreshmanTicket($profile);

        $this->getJson('/api/v1/public/queue-view/'.$issued->plainToken)
            ->assertOk()
            ->assertJsonPath('data.stage', 'transferee_awaiting_payment')
            ->assertJsonPath('data.ticket.ticket_number', 'Q001');

        $profile->forceFill(['freshman_payment_confirmed_at' => now()])->save();
        $issued->ticket->forceFill(['status' => QueueTicketStatus::Served, 'served_at' => now()])->save();

        $this->getJson('/api/v1/public/queue-view/'.$issued->plainToken)
            ->assertOk()
            ->assertJsonPath('data.stage', 'transferee_paid')
            ->assertJsonPath('data.ticket.status', 'served');
    }

    public function test_a_transferees_link_stops_saying_it_waits_for_the_registrar_once_they_are_enrolled(): void
    {
        // Found by the real-browser pass: the link kept saying "waits for Registrar approval" after the Registrar had approved.
        $profile = $this->freshmanProfile('2026-0900', StudentType::Transferee);
        $issued = $this->issueFreshmanTicket($profile);
        $profile->forceFill(['freshman_payment_confirmed_at' => now()])->save();
        $issued->ticket->forceFill(['status' => QueueTicketStatus::Served, 'served_at' => now()])->save();
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $enrollment = Enrollment::create([
            'student_id' => $profile->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingRegistrarApproval, 'total_units' => 5,
        ]);

        $this->getJson('/api/v1/public/queue-view/'.$issued->plainToken)
            ->assertOk()
            ->assertJsonPath('data.stage', 'transferee_paid');

        $enrollment->forceFill(['status' => EnrollmentStatus::Enrolled])->save();

        $this->getJson('/api/v1/public/queue-view/'.$issued->plainToken)
            ->assertOk()
            ->assertJsonPath('data.stage', 'transferee_enrolled')
            ->assertJsonPath('data.ticket.status', 'served');
    }

    public function test_a_new_link_replaces_the_old_one(): void
    {
        $issued = $this->issueFreshmanTicket($this->freshmanProfile());
        $new = $this->app->make(RotateFreshmanQueueToken::class)->execute($issued->ticket);

        $this->getJson('/api/v1/public/queue-view/'.$issued->plainToken)->assertNotFound();
        $this->getJson('/api/v1/public/queue-view/'.$new)->assertOk();
    }

    public function test_the_response_is_never_cached(): void
    {
        $issued = $this->issueFreshmanTicket($this->freshmanProfile());

        $this->getJson('/api/v1/public/queue-view/'.$issued->plainToken)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_one_link_is_rate_limited_but_another_link_is_not(): void
    {
        $first = $this->issueFreshmanTicket($this->freshmanProfile('2026-10-00001'));
        $second = $this->issueFreshmanTicket($this->freshmanProfile('2026-10-00002'));

        for ($request = 0; $request < 40; $request++) {
            $this->getJson('/api/v1/public/queue-view/'.$first->plainToken)->assertOk();
        }

        $this->getJson('/api/v1/public/queue-view/'.$first->plainToken)->assertStatus(429);
        // A different freshman behind the same campus network is unaffected.
        $this->getJson('/api/v1/public/queue-view/'.$second->plainToken)->assertOk();
    }
}
