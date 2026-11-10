<?php

namespace App\Models;

use App\Domain\Organization\SectionPlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AcademicTermSectionPlan extends Model
{
    protected $table = 'academic_term_section_plans';

    protected $fillable = [
        'academic_term_id', 'curriculum_id', 'college', 'year_level',
        'section_count', 'students_per_block', 'recommendation_source',
        'recommended_section_count', 'recommendation_is_overridden', 'recommendation_prediction_run_id',
        'status', 'submitted_by', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'section_count' => 'integer',
            'students_per_block' => 'integer',
            'recommended_section_count' => 'integer',
            'recommendation_is_overridden' => 'boolean',
            'status' => SectionPlanStatus::class,
            'submitted_at' => 'immutable_datetime',
        ];
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'section_plan_id');
    }
}
