<?php

namespace App\Actions\Scheduling;

use App\Domain\Identity\FacultyEmploymentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Scheduling\EffectiveFacultyLoadLimit;
use App\Models\AcademicTerm;
use App\Models\FacultyAssignmentRecommendation;
use App\Models\FacultyLoadLimit;
use App\Models\FacultyLoadOverride;
use App\Models\FacultyLoadThreshold;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\User;

final class BuildFacultyLoadReport
{
    /** @return array<string, mixed> */
    public function execute(AcademicTerm $term, string $college): array
    {
        $threshold = FacultyLoadThreshold::query()
            ->where('academic_term_id', $term->id)
            ->where('college', $college)
            ->value('max_units');
        $collegeDefault = $threshold === null ? null : (float) $threshold;
        // Per employment type, then per professor (ADR 0033). Highest wins:
        // override, type limit, college default, no limit.
        $limitsByType = FacultyLoadLimit::query()
            ->where('academic_term_id', $term->id)
            ->where('college', $college)
            ->get()
            ->mapWithKeys(fn (FacultyLoadLimit $limit): array => [$limit->employment_type->value => $limit->max_units])
            ->all();
        $overrides = FacultyLoadOverride::query()
            ->where('academic_term_id', $term->id)
            ->get()
            ->keyBy('professor_id');
        $latestRun = ScheduleGenerationRun::query()
            ->where('academic_term_id', $term->id)
            ->where('college', $college)
            ->latest('id')
            ->first();
        $recommendations = $latestRun === null
            ? collect()
            : FacultyAssignmentRecommendation::query()
                ->where('schedule_generation_run_id', $latestRun->id)
                ->get()
                ->keyBy('section_id');
        $sections = Section::query()
            ->with(['subject', 'professor'])
            ->where('academic_term_id', $term->id)
            ->whereHas('sectionPlan', fn ($plans) => $plans->where('college', $college))
            ->orderBy('id')
            ->get();

        $rows = $sections->map(function (Section $section) use ($recommendations): array {
            $recommendation = $recommendations->get($section->id);
            $manual = $recommendation !== null && $section->professor_id !== $recommendation->recommended_professor_id;

            return [
                'section_id' => $section->id,
                'section_code' => $section->section_code,
                'subject_id' => $section->subject_id,
                'subject_code' => $section->subject->code,
                'subject_title' => $section->subject->title,
                'units' => (float) $section->subject->units,
                'professor_id' => $section->professor_id,
                'professor_name' => $section->professor?->name,
                'professor_employment_type' => $section->professor?->employment_type?->value,
                'recommended_professor_id' => $recommendation?->recommended_professor_id,
                'rationale' => $manual
                    ? ['manual_override']
                    : ($recommendation?->rationale ?? ['manual_assignment_pending']),
                'override_reason' => $section->manual_override_reason,
                'schedule_days' => $section->schedule_days,
                'starts_at_time' => $section->starts_at_time,
                'ends_at_time' => $section->ends_at_time,
                'room' => $section->room,
                'modality' => $section->modality?->value,
            ];
        });
        $faculty = $rows->filter(fn (array $row): bool => $row['professor_id'] !== null)
            ->groupBy('professor_id')
            ->map(function ($assignments) use ($collegeDefault, $limitsByType, $overrides): array {
                $first = $assignments->first();
                $totalUnits = (float) $assignments->sum('units');
                $type = FacultyEmploymentType::tryFrom((string) $first['professor_employment_type']);
                $override = $overrides->get($first['professor_id']);
                $effective = EffectiveFacultyLoadLimit::resolve($type, $limitsByType, $collegeDefault, $override?->max_units);

                return [
                    'professor_id' => $first['professor_id'],
                    'professor_name' => $first['professor_name'],
                    'employment_type' => $type?->value,
                    'employment_type_label' => $type?->label(),
                    'total_units' => $totalUnits,
                    'max_units' => $effective['max_units'],
                    'limit_source' => $effective['source'],
                    'override' => $override === null ? null : ['max_units' => $override->max_units, 'reason' => $override->reason],
                    'overloaded' => $effective['max_units'] !== null && $totalUnits > $effective['max_units'],
                    'assignments' => $assignments->values()->all(),
                ];
            })->values();
        // Professors of this college with no section this term, so the Dean can
        // see who still has room and assign them.
        $assignedIds = $faculty->pluck('professor_id')->all();
        $idleFaculty = User::query()
            ->where('role', UserRole::Faculty)
            ->where('status', UserStatus::Active)
            ->where('college', $college)
            ->whereNotIn('id', $assignedIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'employment_type'])
            ->map(function (User $professor) use ($collegeDefault, $limitsByType, $overrides): array {
                $override = $overrides->get($professor->id);
                $effective = EffectiveFacultyLoadLimit::resolve($professor->employment_type, $limitsByType, $collegeDefault, $override?->max_units);

                return [
                    'professor_id' => $professor->id,
                    'professor_name' => $professor->name,
                    'employment_type' => $professor->employment_type?->value,
                    'employment_type_label' => $professor->employment_type?->label(),
                    'max_units' => $effective['max_units'],
                    'limit_source' => $effective['source'],
                    'override' => $override === null ? null : ['max_units' => $override->max_units, 'reason' => $override->reason],
                ];
            })
            ->values()
            ->all();
        $totalUnits = (float) $rows->sum('units');
        $numericThreshold = $threshold === null ? null : (float) $threshold;

        return [
            'academic_term_id' => $term->id,
            'college' => $college,
            'threshold_units' => $numericThreshold,
            'limits' => array_map(
                fn (FacultyEmploymentType $type): array => [
                    'employment_type' => $type->value,
                    'label' => $type->label(),
                    'max_units' => $limitsByType[$type->value] ?? null,
                ],
                FacultyEmploymentType::cases(),
            ),
            'required_teaching_units' => $totalUnits,
            'required_assignments' => $rows->count(),
            'equivalent_faculty_loads' => $numericThreshold === null || $numericThreshold <= 0
                ? null
                : (int) ceil($totalUnits / $numericThreshold),
            'assigned_count' => $rows->whereNotNull('professor_id')->count(),
            'unassigned_count' => $rows->whereNull('professor_id')->count(),
            'overloaded_count' => $faculty->where('overloaded', true)->count(),
            'faculty' => $faculty->all(),
            'idle_faculty' => $idleFaculty,
            'unassigned' => $rows->whereNull('professor_id')->values()->all(),
        ];
    }
}
