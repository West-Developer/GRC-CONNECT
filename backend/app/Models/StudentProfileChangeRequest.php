<?php

namespace App\Models;

use App\Domain\Identity\StudentProfileChangeRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $student_id
 * @property string $requested_first_name
 * @property ?string $requested_middle_name
 * @property ?string $requested_middle_initial
 * @property string $requested_last_name
 * @property ?string $requested_suffix
 * @property string $requested_email
 * @property string $requested_address
 * @property string $reason
 * @property CarbonImmutable $base_profile_updated_at
 * @property StudentProfileChangeRequestStatus $status
 * @property ?int $decided_by
 * @property ?string $decision_notes
 * @property ?CarbonImmutable $identity_verified_at
 * @property ?CarbonImmutable $decided_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read StudentProfile $student
 * @property-read ?User $decider
 */
final class StudentProfileChangeRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'requested_first_name',
        'requested_middle_name',
        'requested_middle_initial',
        'requested_last_name',
        'requested_suffix',
        'requested_email',
        'requested_address',
        'reason',
        'base_profile_updated_at',
        'status',
        'decided_by',
        'decision_notes',
        'identity_verified_at',
        'decided_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'base_profile_updated_at' => 'immutable_datetime',
            'status' => StudentProfileChangeRequestStatus::class,
            'identity_verified_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
