<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\QuoteFullPayment;
use App\Domain\Billing\FullPaymentDiscountPolicy;
use App\Domain\Billing\FullPaymentQuote;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * No database: assessments are built in memory and the policy is passed in.
 */
final class QuoteFullPaymentTest extends TestCase
{
    /**
     * @param  list<array<string, string>>  $lines
     */
    private function assessment(array $lines, string $total): Assessment
    {
        $assessment = new Assessment(['total_amount' => $total]);
        $assessment->setRelation('items', collect(array_map(fn (array $line): AssessmentItem => new AssessmentItem($line), $lines)));

        return $assessment;
    }

    /** @return list<array<string, string>> */
    private function plainLines(): array
    {
        return [
            ['category' => 'tuition', 'label' => 'Tuition', 'amount' => '750.00'],
            ['category' => 'miscellaneous', 'label' => 'Registration', 'amount' => '300.00'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Manila'));
    }

    public function test_an_active_discount_is_taken_from_the_whole_assessment(): void
    {
        $quote = (new QuoteFullPayment)->forAssessment(
            $this->assessment($this->plainLines(), '1050.00'),
            new FullPaymentDiscountPolicy('10.0'),
        );

        self::assertTrue($quote->applies());
        self::assertSame('1050.00', $quote->baseTotal);
        self::assertSame('10.0', $quote->percentage);
        self::assertSame('105.00', $quote->discountAmount);
        self::assertSame('945.00', $quote->netTotal);
        self::assertNull($quote->reason);
    }

    public function test_a_scholar_gets_no_second_discount_and_pays_the_net_they_already_owe(): void
    {
        $lines = [...$this->plainLines(), ['category' => 'scholarship_discount', 'label' => 'Scholarship discount (40%)', 'quantity' => '40.0', 'amount' => '-420.00']];

        $quote = (new QuoteFullPayment)->forAssessment($this->assessment($lines, '630.00'), new FullPaymentDiscountPolicy('10.0'));

        self::assertFalse($quote->applies());
        self::assertSame(FullPaymentQuote::REASON_SCHOLAR, $quote->reason);
        self::assertSame('630.00', $quote->netTotal);
        self::assertNull($quote->discountAmount);
    }

    public function test_a_closed_off_or_not_yet_open_discount_collects_the_plain_total_and_says_why(): void
    {
        $assessment = $this->assessment($this->plainLines(), '1050.00');
        $action = new QuoteFullPayment;

        $expired = $action->forAssessment($assessment, new FullPaymentDiscountPolicy('10.0', '2026-10-01', '2026-10-11'));
        $scheduled = $action->forAssessment($assessment, new FullPaymentDiscountPolicy('10.0', '2026-10-13', null));
        $off = $action->forAssessment($assessment, new FullPaymentDiscountPolicy('0.0'));

        self::assertSame('expired', $expired->reason);
        self::assertSame('scheduled', $scheduled->reason);
        self::assertSame('off', $off->reason);
        foreach ([$expired, $scheduled, $off] as $quote) {
            self::assertFalse($quote->applies());
            self::assertSame('1050.00', $quote->netTotal);
        }
    }

    public function test_the_window_is_read_in_manila_time_not_utc(): void
    {
        // 00:30 on 12 October in Manila is still 11 October in UTC; a window ending on the 12th is open.
        $this->travelTo(Carbon::parse('2026-10-12 00:30:00', 'Asia/Manila'));

        self::assertSame('2026-10-12', FullPaymentDiscountPolicy::today());
        $quote = (new QuoteFullPayment)->forAssessment(
            $this->assessment($this->plainLines(), '1050.00'),
            new FullPaymentDiscountPolicy('10.0', '2026-10-12', '2026-10-12'),
        );
        self::assertTrue($quote->applies());
    }

    public function test_an_estimate_is_quoted_the_same_way(): void
    {
        $quote = (new QuoteFullPayment)->forEstimate('4700.00', new FullPaymentDiscountPolicy('10.0'));

        self::assertTrue($quote->applies());
        self::assertSame('470.00', $quote->discountAmount);
        self::assertSame('4230.00', $quote->netTotal);
    }

    public function test_no_estimate_means_no_quote(): void
    {
        $quote = (new QuoteFullPayment)->forEstimate(null, new FullPaymentDiscountPolicy('10.0'));

        self::assertFalse($quote->applies());
        self::assertSame(FullPaymentQuote::REASON_NO_ESTIMATE, $quote->reason);
        self::assertNull($quote->netTotal);
        self::assertNull($quote->baseTotal);
    }
}
