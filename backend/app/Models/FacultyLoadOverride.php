<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One professor's own maximum for one term, set by the Program Head or the
 * Dean with a reason (ADR 0033). It outranks every other limit.
 *
 * @property int $id
 * @property int $academic_term_id
 * @property int $professor_id
 * @property float $max_units
 * @property string $reason
 * @property ?int $set_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read User $professor
 */
final class FacultyLoadOverride extends Model
{
    /** @var list<string> */
    protected $fillable = ['academic_term_id', 'professor_id', 'max_units', 'reason', 'set_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_units' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /** @return BelongsTo<User, $this> */
    public function professor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professor_id');
    }
}
