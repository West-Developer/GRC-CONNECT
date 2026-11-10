<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Billing\FullPaymentQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the Cashier's payment modal needs to offer Pay in full (ADR 0046). `net_total` is always
 * what a full payment collects, whether or not a discount applies.
 *
 * @property-read FullPaymentQuote $resource
 */
final class PaymentQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;

        return [
            'type' => 'payment_quote',
            'total_amount' => $quote->baseTotal,
            'full_payment' => [
                'applies' => $quote->applies(),
                'percentage' => $quote->percentage,
                'discount_amount' => $quote->discountAmount,
                'net_total' => $quote->netTotal,
                'reason' => $quote->reason,
            ],
        ];
    }
}
