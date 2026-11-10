<?php

namespace App\Models;

use App\Domain\Billing\FullPaymentDiscountPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one row that configures the full-payment discount (ADR 0046): its percentage and the
 * school-calendar dates between which it is valid (both inclusive, NULL = open-ended).
 * Only the Registrar Head changes it. Read it with `current()`, never with a bare query, so
 * a database that has never saved one still has the configured default.
 *
 * @property int $id
 * @property numeric-string $percentage
 * @property ?CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_until
 * @property ?int $updated_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read ?User $updater
 */
final class PaymentDiscountSetting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'percentage',
        'valid_from',
        'valid_until',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:1',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
        ];
    }

    /**
     * The setting, created on first use from `config('fees.full_payment_discount_percentage')`.
     * Two requests racing on the very first read could create two rows; both then read the
     * lowest id, so they still agree.
     */
    public static function current(): self
    {
        $existing = self::query()->orderBy('id')->first();

        if ($existing instanceof self) {
            return $existing;
        }

        /** @var numeric-string $configured */
        $configured = (string) config('fees.full_payment_discount_percentage', '10.0');

        return self::query()->create([
            'percentage' => bcadd($configured, '0', 1),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function toPolicy(): FullPaymentDiscountPolicy
    {
        return new FullPaymentDiscountPolicy(
            $this->percentage,
            $this->valid_from?->toDateString(),
            $this->valid_until?->toDateString(),
        );
    }
}
