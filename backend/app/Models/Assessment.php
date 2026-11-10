<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What an enrollment was assessed to owe (PRD §5.3 process 3.3), computed
 * once at Registrar approval by `App\Actions\Billing\AssessEnrollment` and
 * never recomputed — see that Action's docblock. Immutable historical
 * record even if the enrollment is later voided.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property ?numeric-string $total_amount
 * @property string $currency
 * @property CarbonImmutable $assessed_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Enrollment $enrollment
 * @property-read Collection<int, AssessmentItem> $items
 */
final class Assessment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'total_amount',
        'currency',
        'assessed_at',
    ];

    /**
     * `total_amount` stays an exact decimal string rather than a float —
     * money must not go through binary floating point. See `App\Models\
     * Payment`'s identical convention and rationale.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessed_at' => 'immutable_datetime',
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
     * @return HasMany<AssessmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AssessmentItem::class);
    }
}
