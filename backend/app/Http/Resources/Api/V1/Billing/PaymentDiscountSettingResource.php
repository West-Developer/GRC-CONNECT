<?php

namespace App\Http\Resources\Api\V1\Billing;

use App\Domain\Billing\FullPaymentDiscountPolicy;
use App\Models\PaymentDiscountSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read PaymentDiscountSetting $resource */
final class PaymentDiscountSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $setting = $this->resource;

        return [
            'type' => 'payment_discount_setting',
            'percentage' => (string) $setting->percentage,
            'valid_from' => $setting->valid_from?->toDateString(),
            'valid_until' => $setting->valid_until?->toDateString(),
            'status' => $setting->toPolicy()->status(FullPaymentDiscountPolicy::today()),
            'updated_by' => $setting->updater?->name,
            'updated_at' => $setting->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
