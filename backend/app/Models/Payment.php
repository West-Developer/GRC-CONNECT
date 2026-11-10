<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that Accounting confirmed an externally received payment
 * (PRD §5.3 FR-FIN-007). This is not a payment gateway record and holds no
 * cardholder or bank data.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $confirmed_by
 * @property ?string $external_reference
 * @property ?numeric-string $amount
 * @property bool $promissory_note_on_file
 * @property CarbonImmutable $confirmed_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Enrollment $enrollment
 * @property-read User $confirmer
 */
final class Payment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'confirmed_by',
        'external_reference',
        'amount',
        'promissory_note_on_file',
        'confirmed_at',
    ];

    /**
     * `amount` stays an exact decimal string rather than a float: money must
     * not go through binary floating point. PRD §17 also leaves the required
     * payment-confirmation fields open, so no currency or rounding rule is
     * assumed here.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'promissory_note_on_file' => 'boolean',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
