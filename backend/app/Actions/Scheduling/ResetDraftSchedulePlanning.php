<?php

namespace App\Actions\Scheduling;

use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\ScheduleProposal;
use App\Models\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-off operational reset for a college's unsubmitted planning draft.
 *
 * It is deliberately not exposed as an HTTP endpoint: resetting draft work is
 * an explicit local maintenance operation, not a Program Chair workflow.
 */
final class ResetDraftSchedulePlanning
{
    /** @return array{sections: int, plans: int} */
    public function execute(AcademicTerm $term, CollegeCode $college): array
    {
        return DB::transaction(function () use ($term, $college): array {
            $plans = AcademicTermSectionPlan::query()
                ->where('academic_term_id', $term->id)
                ->where('college', $college->value)
                ->lockForUpdate()
                ->get();

            if ($plans->contains(fn (AcademicTermSectionPlan $plan): bool => $plan->status !== SectionPlanStatus::Draft)) {
                throw ValidationException::withMessages([
                    'plans' => 'Only draft section plans may be reset.',
                ]);
            }

            if (ScheduleProposal::query()
                ->where('academic_term_id', $term->id)
                ->where('college', $college->value)
                ->exists()) {
                throw ValidationException::withMessages([
                    'schedule_proposals' => 'A schedule proposal exists for this college and term.',
                ]);
            }

            $sections = Section::query()
                ->whereIn('section_plan_id', $plans->pluck('id'))
                ->lockForUpdate()
                ->get();

            if ($sections->contains(fn (Section $section): bool => $section->enrolled_count > 0)) {
                throw ValidationException::withMessages([
                    'sections' => 'Sections with enrolled students cannot be reset.',
                ]);
            }

            if ($sections->contains(fn (Section $section): bool => $section->status !== SectionStatus::Planned)) {
                throw ValidationException::withMessages([
                    'sections' => 'Only planned sections may be reset.',
                ]);
            }

            $sectionCount = $sections->count();
            Section::query()->whereKey($sections->pluck('id'))->delete();
            $planCount = $plans->count();
            AcademicTermSectionPlan::query()->whereKey($plans->pluck('id'))->delete();

            return ['sections' => $sectionCount, 'plans' => $planCount];
        });
    }
}
