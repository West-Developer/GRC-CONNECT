<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manually received payment applied to a prior outstanding enrollment
 * balance. Amount deliberately remains a raw decimal string: money must not
 * use binary floating point (the same rule as `Payment::$amount`).
 *
 * @property int $id
 * @property int $student_id
 * @property ?int $enrollment_id
 * @property int $received_by
 * @property numeric-string $amount
 * @property CarbonImmutable $received_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read StudentProfile $student
 * @property-read ?Enrollment $enrollment
 * @property-read User $receiver
 */
final class AccountPayment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'enrollment_id',
        'received_by',
        'amount',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
