<?php

namespace App\Actions\Organization;

use App\Actions\Scheduling\NotifyScheduleTransition;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\CapacitySource;
use App\Domain\Organization\SectionBlockCode;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ScheduleProposal;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveSectionPlan
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly NotifyScheduleTransition $notifyScheduleTransition,
    ) {}

    /**
     * @param  array<int|string, int>  $counts  block count per year level
     * @param  array<int|string, int>  $studentsPerBlock  seat capacity per year level
     */
    public function save(
        AcademicTerm $term,
        int $curriculumId,
        User $actor,
        array $counts,
        array $studentsPerBlock = [],
    ): array {
        $college = $actor->college?->value;
        if ($college === null) {
            throw ValidationException::withMessages(['college' => 'A college-scoped Program Head is required.']);
        }
        $this->assertCurriculumBelongsToCollege($curriculumId, $actor, $college);

        return DB::transaction(function () use ($term, $curriculumId, $counts, $studentsPerBlock, $college): array {
            $existingPlans = AcademicTermSectionPlan::query()
                ->where('academic_term_id', $term->id)
                ->where('curriculum_id', $curriculumId)
                ->where('college', $college)
                ->lockForUpdate()
                ->get()
                ->keyBy('year_level');

            foreach (range(1, 4) as $yearLevel) {
                $existing = $existingPlans->get($yearLevel);
                $hasRequestedCount = array_key_exists($yearLevel, $counts)
                    || array_key_exists((string) $yearLevel, $counts);
                $requestedCount = (int) ($counts[$yearLevel] ?? $counts[(string) $yearLevel] ?? 0);

                if ($existing?->status === SectionPlanStatus::Submitted) {
                    if ($hasRequestedCount && $requestedCount !== $existing->section_count) {
                        throw ValidationException::withMessages(['status' => 'This section plan has already been submitted and is locked for editing.']);
                    }

                    continue;
                }

                if ($existing === null) {
                    continue;
                }

                $protectedBlock = $this->highestProtectedBlock($existing, $yearLevel);
                if ($protectedBlock !== null && $requestedCount < $protectedBlock['number']) {
                    throw ValidationException::withMessages([
                        'counts' => "Cannot reduce {$yearLevel}th-year sections below {$protectedBlock['number']} while {$protectedBlock['section']->section_code} has assigned schedule or enrollment data.",
                    ]);
                }
            }

            $plans = [];
            foreach (range(1, 4) as $yearLevel) {
                $existing = $existingPlans->get($yearLevel);

                if ($existing?->status === SectionPlanStatus::Submitted) {
                    $plans[] = $existing;

                    continue;
                }

                $hasRequestedCount = array_key_exists($yearLevel, $counts)
                    || array_key_exists((string) $yearLevel, $counts);
                if ($existing === null && ! $hasRequestedCount) {
                    continue;
                }

                // Omitting a year level leaves its stored capacity alone
                // rather than silently resetting it to the column default.
                $capacity = $studentsPerBlock[$yearLevel] ?? $studentsPerBlock[(string) $yearLevel] ?? null;
                $existingCapacity = $existing !== null ? $existing->students_per_block : null;

                $plans[] = AcademicTermSectionPlan::updateOrCreate(
                    [
                        'academic_term_id' => $term->id,
                        'curriculum_id' => $curriculumId,
                        'college' => $college,
                        'year_level' => $yearLevel,
                    ],
                    [
                        'section_count' => (int) ($counts[$yearLevel] ?? $counts[(string) $yearLevel] ?? 0),
                        'students_per_block' => max(1, (int) ($capacity ?? $existingCapacity ?? 40)),
                        'recommendation_is_overridden' => true,
                        'status' => SectionPlanStatus::Draft,
                        'submitted_by' => null,
                        'submitted_at' => null,
                    ],
                );
            }

            return $plans;
        });
    }

    public function release(
        AcademicTerm $term,
        int $curriculumId,
        User $actor,
        AuditRequestContext $context,
        ?int $yearLevel = null,
    ): array {
        $college = $actor->college?->value;
        if ($college === null) {
            throw ValidationException::withMessages(['college' => 'A college-scoped Program Head is required.']);
        }
        $this->assertCurriculumBelongsToCollege($curriculumId, $actor, $college);
        $curriculum = Curriculum::query()->with('program')->findOrFail($curriculumId);

        return DB::transaction(function () use ($term, $curriculumId, $actor, $college, $curriculum, $yearLevel): array {
            $plans = AcademicTermSectionPlan::query()
                ->where('academic_term_id', $term->id)
                ->where('curriculum_id', $curriculumId)
                ->where('college', $college)
                ->when($yearLevel !== null, fn ($query) => $query->where('year_level', $yearLevel))
                ->lockForUpdate()
                ->get()
                ->keyBy('year_level');

            // A curriculum's real current cohort does not always span all
            // 4 year levels (e.g. no continuing students in the upper years
            // yet, or missing historical demand data for one of them) — a
            // Program Chair must still be able to release and submit
            // whatever years genuinely have a plan, rather than being
            // blocked until every one of the 4 is filled in. Only a
            // curriculum with no plan at all has nothing to release.
            if (($yearLevel === null && $plans->isEmpty()) || ($yearLevel !== null && ! $plans->has($yearLevel))) {
                throw ValidationException::withMessages(['counts' => 'Complete the 1st through 4th year section counts first.']);
            }

            $configuredOfferings = SubjectOffering::query()
                ->where('academic_term_id', $term->id)
                ->where('curriculum_id', $curriculumId)
                ->where(function ($query) use ($term): void {
                    $query->where('semester', $term->semester)
                        ->orWhere('semester', 'like', '%'.$term->semester.'%');
                })
                ->with('subject')
                ->get()
                ->keyBy('subject_id');

            // Always start from the active curriculum. A single manually
            // configured offering must not hide the catalog's other year
            // levels; configured capacities simply override the default for
            // the matching subject.
            $placements = CurriculumSubject::query()
                ->where('curriculum_id', $curriculumId)
                ->where(function ($query) use ($term): void {
                    $query->where('semester', $term->semester)
                        ->orWhere('semester', 'like', '%'.$term->semester.'%');
                })
                ->with('subject')
                ->get()
                ->map(fn ($placement) => (object) [
                    'subject_id' => $placement->subject_id,
                    'max_section_capacity' => $configuredOfferings->get($placement->subject_id)?->max_section_capacity,
                    'year_level' => $placement->year_level ?? 1,
                ]);

            $knownSubjectIds = $placements->pluck('subject_id')->all();
            $offerings = $placements
                ->concat($configuredOfferings->reject(fn ($offering): bool => in_array($offering->subject_id, $knownSubjectIds, true))->values())
                ->groupBy(fn ($offering) => $offering->year_level ?? 1);

            // Older test data used `1A`/`2A` as generated block names. They
            // are stale identifiers, not user-authored sections, so remove
            // them only inside this term's section plans before recreating
            // the current catalog-backed identifiers.
            Section::query()
                ->whereIn('section_plan_id', $plans->pluck('id'))
                ->whereRaw("section_code REGEXP '^[1-4][A-Z]$'")
                ->delete();

            foreach ($plans as $yearLevelKey => $plan) {
                $this->restoreProtectedBlockCount($plan, (int) $yearLevelKey);
                $this->removeReducedGeneratedSections($plan, (int) $yearLevelKey);

                $yearOfferings = $offerings->get((int) $yearLevelKey) ?? collect();
                for ($number = 1; $number <= $plan->section_count; $number++) {
                    foreach ($yearOfferings as $offering) {
                        $code = SectionBlockCode::fromProgram(
                            $curriculum->program->code,
                            $curriculum->program->college ?? $actor->college,
                            (int) $yearLevelKey,
                            $number,
                        );
                        $this->upsertGeneratedSection($term, $plan, (int) $offering->subject_id, $code, $offering->max_section_capacity);
                    }
                }
            }

            return $plans->values()->all();
        });
    }

    /**
     * Create the block section, or bring an existing one back in line with
     * its plan.
     *
     * This deliberately replaces a `firstOrCreate`, which never updated an
     * existing row — the reason generated sections stayed pinned to
     * whatever capacity they were first created with and why
     * `is_block_exclusive` was left NULL on every section ever generated.
     *
     * Two attributes are held back on update:
     *   - `capacity` only follows the plan while `capacity_source` is
     *     `Plan`. Release re-runs on every Add/Remove-section click, so
     *     overwriting a `Manual` capacity here would silently discard the
     *     per-section figure the Chair typed.
     *   - `status` is set at creation only; a section that has already been
     *     published must not be demoted back to Planned by a later release.
     *
     * `$subjectCapacity` is the subject's own `SubjectOffering.
     * max_section_capacity`, when the Program Chair configured one (e.g. to
     * allot extra seats to a single subject for irregular students) — it
     * takes precedence over the year level's `students_per_block` for that
     * subject only, every other subject in the same year level still
     * inherits the block figure.
     */
    private function upsertGeneratedSection(
        AcademicTerm $term,
        AcademicTermSectionPlan $plan,
        int $subjectId,
        string $code,
        ?int $subjectCapacity,
    ): void {
        $identity = [
            'academic_term_id' => $term->id,
            'subject_id' => $subjectId,
            'section_code' => $code,
        ];

        $section = Section::query()->where($identity)->first();
        $planCapacity = max(1, (int) ($subjectCapacity ?? $plan->students_per_block));

        if ($section === null) {
            Section::create($identity + [
                'section_plan_id' => $plan->id,
                'capacity' => $planCapacity,
                'capacity_source' => CapacitySource::Plan,
                'is_block_exclusive' => true,
                'status' => SectionStatus::Planned,
            ]);

            return;
        }

        $changes = [
            'section_plan_id' => $plan->id,
            'is_block_exclusive' => true,
        ];

        if ($section->capacity_source !== CapacitySource::Manual) {
            $changes['capacity'] = $planCapacity;
            $changes['capacity_source'] = CapacitySource::Plan;
        }

        $section->update($changes);
    }

    private function removeReducedGeneratedSections(AcademicTermSectionPlan $plan, int $yearLevel): void
    {
        $sections = Section::query()->where('section_plan_id', $plan->id)->get();

        foreach ($sections as $section) {
            if (! preg_match('/'.$yearLevel.'(\d{2})$/u', $section->section_code, $matches)) {
                continue;
            }

            if ((int) $matches[1] <= $plan->section_count) {
                continue;
            }

            if ($this->hasProtectedAssignmentData($section)) {
                throw ValidationException::withMessages([
                    'counts' => "Cannot reduce {$yearLevel}th-year sections while {$section->section_code} has assigned schedule or enrollment data.",
                ]);
            }

            $section->delete();
        }
    }

    /**
     * A failed remove attempt used to write its reduced plan count before
     * release discovered a scheduled/enrolled block that could not safely be
     * deleted. Reconcile that stale count to the highest protected ordinal
     * before release considers any deletion, so submit remains non-destructive.
     */
    private function restoreProtectedBlockCount(AcademicTermSectionPlan $plan, int $yearLevel): void
    {
        $protectedBlock = $this->highestProtectedBlock($plan, $yearLevel);
        if ($protectedBlock !== null && $protectedBlock['number'] > $plan->section_count) {
            $plan->update(['section_count' => $protectedBlock['number']]);
        }
    }

    /** @return ?array{section: Section, number: int} */
    private function highestProtectedBlock(AcademicTermSectionPlan $plan, int $yearLevel): ?array
    {
        $highest = null;

        $sections = Section::query()
            ->where('section_plan_id', $plan->id)
            ->lockForUpdate()
            ->get();
        foreach ($sections as $section) {
            if (! preg_match('/'.$yearLevel.'(\d{2})$/u', $section->section_code, $matches)) {
                continue;
            }

            $blockNumber = (int) $matches[1];
            if ($this->hasProtectedAssignmentData($section) && ($highest === null || $blockNumber > $highest['number'])) {
                $highest = ['section' => $section, 'number' => $blockNumber];
            }
        }

        return $highest;
    }

    private function hasProtectedAssignmentData(Section $section): bool
    {
        return $section->enrolled_count > 0 || $section->professor_id !== null || $section->schedule_days !== null
            || $section->starts_at_time !== null || $section->ends_at_time !== null || $section->room !== null
            || $section->status !== SectionStatus::Planned;
    }

    public function submit(
        AcademicTerm $term,
        int $curriculumId,
        User $actor,
        AuditRequestContext $context,
    ): ScheduleProposal {
        $college = $actor->college?->value;
        if ($college === null) {
            throw ValidationException::withMessages(['college' => 'A college-scoped Program Head is required.']);
        }
        $this->assertCurriculumBelongsToCollege($curriculumId, $actor, $college);

        return DB::transaction(function () use ($term, $curriculumId, $actor, $context, $college): ScheduleProposal {
            $this->release($term, $curriculumId, $actor, $context);
            $plans = AcademicTermSectionPlan::query()
                ->where('academic_term_id', $term->id)
                ->where('curriculum_id', $curriculumId)
                ->where('college', $college)
                ->get();
            $sectionIds = $plans->flatMap(fn (AcademicTermSectionPlan $plan) => $plan->sections()->pluck('id'));

            // Incomplete assignments are review information, not a
            // submission blocker. The Program Chair can submit the proposal
            // so the Dean and Executive Director can review its current
            // state, then return it for corrections if needed.
            if ($sectionIds->isEmpty()) {
                throw ValidationException::withMessages(['sections' => 'Generate at least one section before submitting for approval.']);
            }

            $proposal = ScheduleProposal::query()
                ->where('academic_term_id', $term->id)
                ->where('college', $college)
                ->where('status', '!=', 'closed')
                ->lockForUpdate()
                ->first();
            if ($proposal === null) {
                $proposal = ScheduleProposal::create([
                    'academic_term_id' => $term->id,
                    'college' => $college,
                    'submitted_by' => $actor->id,
                    'status' => 'draft',
                    'section_plan_id' => $plans->first()?->id,
                ]);
            } else {
                $proposal->update([
                    'submitted_by' => $actor->id,
                    'section_plan_id' => $plans->first()?->id,
                    'status' => ScheduleProposalStatus::Draft,
                    'decided_by' => null,
                    'decided_at' => null,
                    'decision_reason' => null,
                ]);
            }

            $plans->each(fn (AcademicTermSectionPlan $plan) => $plan->update([
                'status' => SectionPlanStatus::Submitted,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
            ]));

            $workflow = $term->collegeWorkflows()->where('college', $college)->lockForUpdate()->first();
            $wasAlreadySubmittedToDean = $workflow?->stage === AcademicTermCollegeWorkflowStage::ForDeanApproval;
            if ($workflow !== null) {
                $workflow->update([
                    'stage' => AcademicTermCollegeWorkflowStage::ForDeanApproval,
                    'schedule_submitted_by' => $actor->id,
                    'schedule_submitted_at' => now(),
                ]);
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::SECTION_PLAN_SUBMITTED,
                AuditableType::SECTION_PLAN,
                $plans->first()?->id,
                null,
                ['academic_term_id' => $term->id, 'curriculum_id' => $curriculumId, 'college' => $college, 'section_ids' => $sectionIds->values()->all()],
                null,
                $context,
            );

            $proposal->refresh();

            // A college may have several curricula, each calling submit()
            // independently; only the transition into ForDeanApproval — not
            // every subsequent curriculum's submit — should notify the Dean.
            if (! $wasAlreadySubmittedToDean) {
                $this->notifyScheduleTransition->submittedForDean($proposal, $term);
            }

            return $proposal;
        });
    }

    private function assertCurriculumBelongsToCollege(int $curriculumId, User $actor, string $college): void
    {
        if ($actor->role !== UserRole::ProgramChair) {
            return;
        }

        $belongsToCollege = Curriculum::query()
            ->whereKey($curriculumId)
            ->whereHas('program', fn ($programs) => $programs->where('college', $college))
            ->exists();

        if (! $belongsToCollege) {
            throw ValidationException::withMessages(['curriculum_id' => 'This curriculum is not available for your college.']);
        }
    }
}
