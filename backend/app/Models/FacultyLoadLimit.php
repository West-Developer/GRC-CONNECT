<?php

namespace App\Models;

use App\Domain\Identity\FacultyEmploymentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The maximum teaching units for one employment type in one college and term
 * (ADR 0033). Absent means no limit for that type.
 *
 * @property int $id
 * @property int $academic_term_id
 * @property string $college
 * @property FacultyEmploymentType $employment_type
 * @property float $max_units
 * @property ?int $configured_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class FacultyLoadLimit extends Model
{
    /** @var list<string> */
    protected $fillable = ['academic_term_id', 'college', 'employment_type', 'max_units', 'configured_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_type' => FacultyEmploymentType::class,
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
}
