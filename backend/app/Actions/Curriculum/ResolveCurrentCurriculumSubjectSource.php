<?php

namespace App\Actions\Curriculum;

use App\Domain\Curriculum\CurriculumStatus;
use App\Models\Curriculum;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;

final class ResolveCurrentCurriculumSubjectSource
{
    private const RELATIONS = ['subjectPlacements.subject'];

    public function __construct(private readonly ResolveCurriculumEffectiveSchoolYear $effectiveSchoolYearResolver) {}

    public function execute(Program $program): ?Curriculum
    {
        $schoolYear = $this->effectiveSchoolYearResolver->execute();

        $current = $this->activeCurricula($program)
            ->where('effective_school_year', $schoolYear)
            ->first();

        if ($current !== null) {
            return $current;
        }

        return $this->activeCurricula($program)
            ->orderByDesc('effective_school_year')
            ->orderBy('name')
            ->first();
    }

    /**
     * @return Builder<Curriculum>
     */
    private function activeCurricula(Program $program): Builder
    {
        return Curriculum::query()
            ->where('program_id', $program->id)
            ->where('status', CurriculumStatus::Active)
            ->with(self::RELATIONS);
    }
}
