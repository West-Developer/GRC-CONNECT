<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $category
 * @property ?string $semester "1st", "2nd", or null for every semester
 * @property string $label
 * @property string $amount
 * @property array<string>|null $program_codes
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class FeeSchedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'program_codes' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
