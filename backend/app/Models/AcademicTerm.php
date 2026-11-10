<?php

namespace App\Models;

use App\Domain\Enrollment\EnrollmentPlatform;
use App\Domain\Organization\AcademicTermStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $school_year
 * @property string $semester
 * @property ?CarbonImmutable $starts_at
 * @property ?CarbonImmutable $ends_at
 * @property ?CarbonImmutable $enrollment_opens_at
 * @property ?CarbonImmutable $enrollment_closes_at
 * @property ?CarbonImmutable $add_drop_opens_at
 * @property ?CarbonImmutable $add_drop_deadline_at
 * @property ?EnrollmentPlatform $enrollment_platform
 * @property ?CarbonImmutable $grading_deadline_at
 * @property ?CarbonImmutable $closed_at
 * @property ?CarbonImmutable $archived_at
 * @property AcademicTermStatus $status
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class AcademicTerm extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'school_year',
        'semester',
        'starts_at',
        'ends_at',
        'enrollment_opens_at',
        'enrollment_closes_at',
        'add_drop_opens_at',
        'add_drop_deadline_at',
        'enrollment_platform',
        'grading_deadline_at',
        'closed_at',
        'archived_at',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'enrollment_opens_at' => 'immutable_datetime',
            'enrollment_closes_at' => 'immutable_datetime',
            'add_drop_opens_at' => 'immutable_datetime',
            'add_drop_deadline_at' => 'immutable_datetime',
            'enrollment_platform' => EnrollmentPlatform::class,
            'grading_deadline_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'status' => AcademicTermStatus::class,
        ];
    }

    public function isActionableCurrent(): bool
    {
        if (! in_array($this->status, [
            AcademicTermStatus::Draft,
            AcademicTermStatus::ForDeanApproval,
            AcademicTermStatus::SemesterOngoing,
        ], true)) {
            return false;
        }

        $currentSlotId = DB::table('academic_term_current_slots')
            ->where('id', 1)
            ->value('academic_term_id');

        if ($currentSlotId !== null) {
            return (int) $currentSlotId === (int) $this->id;
        }

        return true;
    }

    /**
     * Computes the next chronological academic term in sequence:
     * - {SY} 1st sem -> {SY} 2nd sem
     * - {SY} 2nd sem -> {SY+1} 1st sem
     *
     * @return array{school_year: string, semester: string}
     */
    public function nextSequence(): array
    {
        return self::computeNextSequence($this->school_year, $this->semester);
    }

    /**
     * @return array{school_year: string, semester: string}
     */
    public static function computeNextSequence(string $schoolYear, string $semester): array
    {
        if ($semester === '1st') {
            return [
                'school_year' => $schoolYear,
                'semester' => '2nd',
            ];
        }

        if (preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $matches)) {
            $nextStart = (int) $matches[1] + 1;
            $nextEnd = (int) $matches[2] + 1;
            $nextSchoolYear = "{$nextStart}-{$nextEnd}";
        } else {
            $nextSchoolYear = $schoolYear;
        }

        return [
            'school_year' => $nextSchoolYear,
            'semester' => '1st',
        ];
    }

    public function chronologicalIndex(): int
    {
        $startYear = (int) substr($this->school_year, 0, 4);
        $semOffset = $this->semester === '1st' ? 0 : 1;

        return ($startYear * 2) + $semOffset;
    }

    public function termsElapsedSince(AcademicTerm $other): int
    {
        return $this->chronologicalIndex() - $other->chronologicalIndex();
    }

    /**
     * @return HasMany<AcademicTermCollegeWorkflow, $this>
     */
    public function collegeWorkflows(): HasMany
    {
        return $this->hasMany(AcademicTermCollegeWorkflow::class);
    }

    /**
     * @return HasMany<AcademicTermEnrollmentWindow, $this>
     */
    public function enrollmentWindows(): HasMany
    {
        return $this->hasMany(AcademicTermEnrollmentWindow::class);
    }

    /**
     * Restricts the result set for learner-scoped roles to learner-visible
     * terms. Planning roles (see UserRole::isLearnerScoped()) are passed
     * through unfiltered — they see every term regardless of status.
     *
     * @param  Builder<AcademicTerm>  $query
     * @return Builder<AcademicTerm>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->role->isLearnerScoped()) {
            return $query;
        }

        $visibleValues = array_values(array_map(
            fn (AcademicTermStatus $status): string => $status->value,
            array_filter(
                AcademicTermStatus::cases(),
                fn (AcademicTermStatus $status): bool => $status->isVisibleToLearners(),
            ),
        ));

        return $query->whereIn('status', $visibleValues);
    }
}
