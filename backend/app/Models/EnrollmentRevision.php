<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One round of a Program Chair's changes to a student's subjects, waiting on
 * (or answered by) the student (ADR 0040).
 *
 * @property int $id
 * @property int $enrollment_id
 * @property ?int $proposed_by
 * @property string $note
 * @property list<array{section_id: int, section_code: ?string, subject_code: string, subject_title: string, units: float}> $added_subjects
 * @property list<array{section_id: int, section_code: ?string, subject_code: string, subject_title: string, units: float}> $removed_subjects
 * @property float $units_before
 * @property float $units_after
 * @property string $status pending|accepted|declined
 * @property ?string $student_reason
 * @property ?CarbonImmutable $responded_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Enrollment $enrollment
 */
final class EnrollmentRevision extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'proposed_by',
        'note',
        'added_subjects',
        'removed_subjects',
        'units_before',
        'units_after',
        'status',
        'student_reason',
        'responded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'added_subjects' => 'array',
            'removed_subjects' => 'array',
            'units_before' => 'float',
            'units_after' => 'float',
            'responded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
