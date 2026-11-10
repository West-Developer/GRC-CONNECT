<?php

namespace App\Models;

use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\CollegeCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $academic_term_id
 * @property CollegeCode $college
 * @property AcademicTermCollegeWorkflowStage $stage
 * @property ?int $curriculum_completed_by
 * @property ?CarbonImmutable $curriculum_completed_at
 * @property ?int $faculty_reviewed_by
 * @property ?CarbonImmutable $faculty_reviewed_at
 * @property ?int $schedule_submitted_by
 * @property ?CarbonImmutable $schedule_submitted_at
 * @property-read AcademicTerm $academicTerm
 */
final class AcademicTermCollegeWorkflow extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'academic_term_id',
        'college',
        'stage',
        'curriculum_completed_by',
        'curriculum_completed_at',
        'faculty_reviewed_by',
        'faculty_reviewed_at',
        'schedule_submitted_by',
        'schedule_submitted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'college' => CollegeCode::class,
            'stage' => AcademicTermCollegeWorkflowStage::class,
            'curriculum_completed_at' => 'immutable_datetime',
            'faculty_reviewed_at' => 'immutable_datetime',
            'schedule_submitted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AcademicTerm, $this>
     */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function isCurriculumEditable(): bool
    {
        return $this->stage === AcademicTermCollegeWorkflowStage::CurriculumPreparation;
    }

    public function hasSubmittedSchedule(): bool
    {
        return $this->stage === AcademicTermCollegeWorkflowStage::ForDeanApproval;
    }
}
