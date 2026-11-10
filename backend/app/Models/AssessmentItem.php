<?php

namespace App\Models;

use App\Domain\Billing\AssessmentItemCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One printed line of an `Assessment` — tuition (with `quantity`/
 * `unit_amount` set) or a flat miscellaneous fee (both null). See
 * `App\Domain\Billing\AssessmentComputation` for how these are computed.
 *
 * @property int $id
 * @property int $assessment_id
 * @property AssessmentItemCategory $category
 * @property string $label
 * @property ?string $quantity
 * @property ?string $unit_amount
 * @property ?string $amount
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Assessment $assessment
 */
final class AssessmentItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'assessment_id',
        'category',
        'label',
        'quantity',
        'unit_amount',
        'amount',
    ];

    /**
     * `quantity`, `unit_amount`, and `amount` all stay exact decimal
     * strings rather than floats — see `App\Models\Payment`'s identical
     * convention and rationale.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AssessmentItemCategory::class,
        ];
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
