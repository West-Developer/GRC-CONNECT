<?php

namespace App\Models;

use App\Domain\Organization\CollegeCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * De-identified, term-level demand evidence used by section forecasting.
 *
 * @property int $id
 * @property int $academic_term_id
 * @property int $program_id
 * @property int $curriculum_id
 * @property int $subject_id
 * @property CollegeCode $college
 * @property int $year_level
 * @property int $cohort_size
 * @property int $enrolled_count
 * @property int $section_count
 * @property int $offered_capacity
 * @property string $source
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class SectionDemandObservation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'academic_term_id',
        'program_id',
        'curriculum_id',
        'subject_id',
        'college',
        'year_level',
        'cohort_size',
        'enrolled_count',
        'section_count',
        'offered_capacity',
        'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'college' => CollegeCode::class,
            'year_level' => 'integer',
            'cohort_size' => 'integer',
            'enrolled_count' => 'integer',
            'section_count' => 'integer',
            'offered_capacity' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<Curriculum, $this> */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
