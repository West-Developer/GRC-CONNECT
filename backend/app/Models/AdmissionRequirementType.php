<?php

namespace App\Models;

use App\Domain\Identity\AdmissionRequirementCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One document on the Admission checklist (ADR 0037). The stakeholder's list is
 * seeded (`is_system`); Admission Staff may append more.
 *
 * @property int $id
 * @property AdmissionRequirementCategory $category
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_system
 * @property ?int $created_by
 * @property ?CarbonImmutable $created_at
 */
final class AdmissionRequirementType extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'category',
        'name',
        'sort_order',
        'is_active',
        'is_system',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AdmissionRequirementCategory::class,
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<StudentAdmissionRequirement, $this>
     */
    public function studentRequirements(): HasMany
    {
        return $this->hasMany(StudentAdmissionRequirement::class, 'requirement_type_id');
    }
}
