<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded course shift (ADR 0034).
 *
 * @property int $id
 * @property int $student_id
 * @property int $from_program_id
 * @property int $to_program_id
 * @property int $academic_term_id
 * @property string $reason
 * @property int $recorded_by
 * @property CarbonImmutable $recorded_at
 * @property-read Program $fromProgram
 * @property-read Program $toProgram
 */
final class ProgramShift extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'from_program_id',
        'to_program_id',
        'academic_term_id',
        'reason',
        'recorded_by',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    /** @return BelongsTo<Program, $this> */
    public function fromProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'from_program_id');
    }

    /** @return BelongsTo<Program, $this> */
    public function toProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'to_program_id');
    }
}
