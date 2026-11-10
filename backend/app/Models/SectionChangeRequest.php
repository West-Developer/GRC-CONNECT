<?php

namespace App\Models;

use App\Domain\Scheduling\SectionChangeRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Program Head's request to change a published section's schedule, waiting
 * for the Registrar Head (ADR 0032).
 *
 * @property int $id
 * @property int $section_id
 * @property int $requested_by
 * @property SectionChangeRequestStatus $status
 * @property string $reason
 * @property array<string, mixed> $old_values
 * @property array<string, mixed> $new_values
 * @property ?int $decided_by
 * @property ?CarbonImmutable $decided_at
 * @property ?string $decision_reason
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Section $section
 * @property-read User $requester
 * @property-read ?User $decider
 */
final class SectionChangeRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'section_id',
        'requested_by',
        'status',
        'reason',
        'old_values',
        'new_values',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SectionChangeRequestStatus::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
