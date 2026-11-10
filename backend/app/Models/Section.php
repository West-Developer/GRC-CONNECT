<?php

namespace App\Models;

use App\Domain\Identity\UserRole;
use App\Domain\Organization\CapacitySource;
use App\Domain\Scheduling\SectionModality;
use App\Domain\Scheduling\SectionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $academic_term_id
 * @property int $subject_id
 * @property string $section_code
 * @property ?int $professor_id
 * @property ?string $schedule_days
 * @property ?string $starts_at_time
 * @property ?string $ends_at_time
 * @property ?string $room
 * @property ?SectionModality $modality
 * @property int $capacity
 * @property CapacitySource $capacity_source
 * @property ?string $recommendation_source
 * @property ?int $recommended_capacity
 * @property ?int $recommendation_prediction_run_id
 * @property ?int $viability_threshold
 * @property int $enrolled_count
 * @property ?bool $is_block_exclusive
 * @property SectionStatus $status
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read AcademicTerm $academicTerm
 * @property-read Subject $subject
 * @property-read ?User $professor
 * @property-read ?AcademicTermSectionPlan $sectionPlan
 * @property-read Collection<int, EnrollmentSubject> $enrollmentSubjects
 * @property-read Collection<int, AcademicGrade> $academicGrades
 */
final class Section extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'academic_term_id',
        'section_plan_id',
        'subject_id',
        'section_code',
        'professor_id',
        'schedule_days',
        'starts_at_time',
        'ends_at_time',
        'room',
        'modality',
        'capacity',
        'capacity_source',
        'recommendation_source',
        'recommended_capacity',
        'recommendation_prediction_run_id',
        'manual_override_reason',
        'viability_threshold',
        'enrolled_count',
        'is_block_exclusive',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SectionStatus::class,
            'modality' => SectionModality::class,
            'capacity' => 'integer',
            'capacity_source' => CapacitySource::class,
            'recommended_capacity' => 'integer',
            'viability_threshold' => 'integer',
            'enrolled_count' => 'integer',
            'is_block_exclusive' => 'boolean',
        ];
    }

    /**
     * Remaining seats, floored at zero so an over-filled section never reports
     * negative availability. This is a convenience for display only — it does
     * NOT authorize enrollment, because the reservation timeout and seat-release
     * rules that would make it authoritative are open PRD §17 decisions.
     */
    public function remainingSeats(): int
    {
        return max(0, $this->capacity - $this->enrolled_count);
    }

    /**
     * @return BelongsTo<AcademicTerm, $this>
     */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function professor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professor_id');
    }

    /** @return BelongsTo<AcademicTermSectionPlan, $this> */
    public function sectionPlan(): BelongsTo
    {
        return $this->belongsTo(AcademicTermSectionPlan::class, 'section_plan_id');
    }

    /**
     * @return HasMany<EnrollmentSubject, $this>
     */
    public function enrollmentSubjects(): HasMany
    {
        return $this->hasMany(EnrollmentSubject::class);
    }

    /**
     * @return HasMany<AcademicGrade, $this>
     */
    public function academicGrades(): HasMany
    {
        return $this->hasMany(AcademicGrade::class);
    }

    /**
     * Faculty receives only the published/closed sections assigned directly
     * through `professor_id`; student and Accounting learner visibility stays
     * status-based. Executive Director receives published sections only for
     * the master schedule; remaining planning roles see every status.
     *
     * @param  Builder<Section>  $query
     * @return Builder<Section>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::ProgramChair && $user->college !== null) {
            $query->where(function (Builder $scoped) use ($user): void {
                $scoped->whereNull('section_plan_id')
                    ->orWhereHas('sectionPlan', fn (Builder $plans) => $plans->where('college', $user->college->value));
            });
        }

        if ($user->role === UserRole::Faculty) {
            return $query
                ->where('professor_id', $user->id)
                ->whereIn('status', self::learnerVisibleStatusValues());
        }

        if ($user->role === UserRole::ExecutiveDirector) {
            return $query->where('status', SectionStatus::Published->value);
        }

        if (! $user->role->isLearnerScoped()) {
            return $query;
        }

        return $query->whereIn('status', self::learnerVisibleStatusValues());
    }

    /** @return list<string> */
    private static function learnerVisibleStatusValues(): array
    {
        $visibleValues = array_values(array_map(
            fn (SectionStatus $status): string => $status->value,
            array_filter(
                SectionStatus::cases(),
                fn (SectionStatus $status): bool => $status->isVisibleToLearners(),
            ),
        ));

        return $visibleValues;
    }
}
