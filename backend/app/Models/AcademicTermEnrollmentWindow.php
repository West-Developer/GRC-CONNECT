<?php

namespace App\Models;

use App\Domain\Enrollment\EnrollmentAudience;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $academic_term_id
 * @property EnrollmentAudience $audience
 * @property ?CarbonImmutable $opens_at
 * @property ?CarbonImmutable $closes_at
 * @property-read AcademicTerm $academicTerm
 */
final class AcademicTermEnrollmentWindow extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'academic_term_id',
        'audience',
        'opens_at',
        'closes_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => EnrollmentAudience::class,
            'opens_at' => 'immutable_datetime',
            'closes_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AcademicTerm, $this>
     */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }
}
