<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That one student handed in one requirement, and when (ADR 0037). No row means
 * "not submitted".
 *
 * @property int $id
 * @property int $student_profile_id
 * @property int $requirement_type_id
 * @property bool $is_submitted
 * @property ?CarbonImmutable $submitted_at
 * @property ?int $recorded_by
 */
final class StudentAdmissionRequirement extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'student_profile_id',
        'requirement_type_id',
        'is_submitted',
        'submitted_at',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_submitted' => 'boolean',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AdmissionRequirementType, $this>
     */
    public function requirementType(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirementType::class, 'requirement_type_id');
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }
}
