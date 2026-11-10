<?php

namespace App\Actions\Organization;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Scheduling\RoomConflictDetector;
use App\Domain\Scheduling\SectionConflictDetector;
use App\Domain\Scheduling\SectionModality;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bulk-fills a term's generated Sections from the matching curriculum
 * placement's `reference_*` schedule/faculty data (see
 * `GrcCurriculumScheduleReferenceSeeder`) — a one-click convenience for
 * manual testing, satisfying `SaveSectionPlan::submit()`'s requirement that
 * every section have a professor/day/times/room/modality before it can go
 * to the Dean.
 *
 * Never overwrites a field a Program Chair already set by hand — only
 * fills currently-null fields, the same "don't clobber manual work"
 * precedent `capacity_source` already established for capacity. A subject
 * whose placement has no `reference_professor_name` stays unassigned; no
 * name is ever invented. For the same reason, a `reference_room` that would
 * double-book an already-scheduled section (any college — rooms are shared
 * campus-wide) is left unset rather than silently overlapping it; the
 * section stays incomplete for a Program Chair, or the catalog-based
 * `GenerateFacultyAssignmentRecommendations`, to resolve instead.
 *
 * Like every sibling write on this workflow (`SaveSectionPlan::save()` /
 * `release()` / `submit()`), this is scoped to the acting Program Chair's
 * own college: the role check alone does not stop one college's Chair from
 * bulk-writing another college's sections.
 */
final class AutoAssignSectionScheduleReferences
{
    /**
     * The legacy reference data's common missing-time fallback is a
     * three-hour morning meeting. The later candidates retain that duration
     * and are only used when the common slot collides within the block.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const FALLBACK_TIME_SLOTS = [
        ['07:30:00', '10:30:00'],
        ['10:30:00', '13:30:00'],
        ['13:30:00', '16:30:00'],
        ['16:30:00', '19:30:00'],
        ['18:00:00', '21:00:00'],
    ];

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly SectionConflictDetector $conflictDetector,
        private readonly RoomConflictDetector $roomConflictDetector,
    ) {}

    /**
     * @return Collection<int, Section> the sections this call actually touched
     */
    public function execute(
        AcademicTerm $term,
        int $curriculumId,
        User $actor,
        AuditRequestContext $context,
        ?int $yearLevel = null,
    ): Collection {
        $college = $actor->college?->value;
        if ($college === null) {
            throw ValidationException::withMessages(['college' => 'A college-scoped Program Head is required.']);
        }
        $this->assertCurriculumBelongsToCollege($curriculumId, $actor, $college);

        return DB::transaction(function () use ($term, $curriculumId, $actor, $context, $college, $yearLevel): Collection {
            $planIds = AcademicTermSectionPlan::query()
                ->where('academic_term_id', $term->id)
                ->where('curriculum_id', $curriculumId)
                ->where('college', $college)
                ->when($yearLevel !== null, fn ($query) => $query->where('year_level', $yearLevel))
                ->pluck('id');

            $sections = Section::query()
                ->whereIn('section_plan_id', $planIds)
                ->where(function ($query): void {
                    $query->whereNull('professor_id')
                        ->orWhereNull('schedule_days')
                        ->orWhereNull('starts_at_time')
                        ->orWhereNull('ends_at_time')
                        ->orWhereNull('room')
                        ->orWhereNull('modality');
                })
                ->get();

            $placements = CurriculumSubject::query()
                ->where('curriculum_id', $curriculumId)
                ->whereIn('subject_id', $sections->pluck('subject_id')->unique())
                ->get()
                ->keyBy('subject_id');

            // Source-recorded meetings are authoritative. Fill them first
            // so a placement with no recorded time chooses around that
            // concrete slot instead of claiming the shared default ahead of
            // it (the former ACC301 failure mode).
            $sections = $sections
                ->sortBy(fn (Section $section): int => $this->hasReferenceTime($placements->get($section->subject_id)) ? 0 : 1)
                ->values();

            $touched = new Collection;
            // Kept alongside `$touched` purely for the audit payload: PHPStan
            // cannot infer an element type for a Collection filled by push(),
            // so reading the ids back off it is not statically checkable.
            $touchedSectionIds = [];

            foreach ($sections as $section) {
                $placement = $placements->get($section->subject_id);

                if ($placement === null) {
                    continue;
                }

                $changes = [];

                if ($section->schedule_days === null && $placement->reference_day !== null) {
                    $changes['schedule_days'] = $placement->reference_day;
                }
                if ($section->starts_at_time === null && $section->ends_at_time === null
                    && $placement->reference_day !== null
                    && ! $this->hasReferenceTime($placement)) {
                    [$changes['starts_at_time'], $changes['ends_at_time']] = $this->availableFallbackTime(
                        $section,
                        $changes['schedule_days'] ?? $section->schedule_days,
                    );
                } else {
                    if ($section->starts_at_time === null && $placement->reference_day !== null) {
                        $changes['starts_at_time'] = $placement->reference_start_time ?? '07:30:00';
                    }
                    if ($section->ends_at_time === null && $placement->reference_day !== null) {
                        $changes['ends_at_time'] = $placement->reference_end_time ?? '10:30:00';
                    }
                }
                if ($section->modality === null) {
                    if ($placement->reference_modality !== null) {
                        // The seeded reference_modality values are uppercase/spaced
                        // (e.g. 'HYFLEX A', 'HYFLEX B', 'F2F' — see
                        // curriculum-2024-2029-schedule-references.csv), but
                        // Section::modality is cast to the SectionModality backed
                        // enum, whose backing values are lowercase/underscored
                        // ('hyflex_a', 'hyflex_b', 'f2f'). Normalize
                        // before assigning, or the enum cast throws a ValueError.
                        $normalized = strtolower(str_replace(' ', '_', $placement->reference_modality));

                        // GRC's schedule taxonomy only has three modalities.
                        // The legacy roster's "ONLINE" designation (from before
                        // the current Hyflex A/B split) has no direct
                        // equivalent, so it falls back to Hyflex A — week 1
                        // face-to-face, alternating — rather than staying an
                        // unresolved gap. A Program Chair can still change it
                        // to Hyflex B or F2F like any other section field.
                        $changes['modality'] = SectionModality::tryFrom($normalized) ?? SectionModality::HyflexA;
                    } elseif ($placement->reference_day !== null) {
                        // Only CCS's rows in the reference CSV carry a
                        // modality at all — COE/COA/CBAE's rows have a real
                        // day/time but leave the modality column blank
                        // outright. Per product direction, that absence
                        // defaults to ordinary face-to-face, the least
                        // assumption-laden option, rather than staying
                        // unresolved. A placement with no reference data at
                        // all (no day either) has nothing to default from.
                        $changes['modality'] = SectionModality::FaceToFace;
                    }
                }
                // The legacy "ONLINE" reference names a placeholder room,
                // never a real one — skip it so the room stays open for the
                // normal room-catalog assignment to fill instead.
                if ($section->room === null && $placement->reference_room !== null
                    && strtolower($placement->reference_room) !== 'online'
                    && ! $this->referenceRoomConflicts($section, $placement->reference_room, $changes)) {
                    $changes['room'] = $placement->reference_room;
                }

                // Resolved last, against this section's FINAL day/time: every
                // block section of one subject names the same reference
                // professor, so assigning it unconditionally handed one
                // person every block at the same hour.
                if ($section->professor_id === null && $placement->reference_professor_name !== null) {
                    $professorId = $this->findOrCreateFaculty($placement->reference_professor_name)->id;

                    if (! $this->referenceProfessorConflicts($section, $professorId, $changes)) {
                        $changes['professor_id'] = $professorId;
                    }
                }

                if ($changes !== []) {
                    $section->update($changes);
                    $touched->push($section);
                    $touchedSectionIds[] = $section->id;
                }
            }

            // A run that filled nothing wrote nothing, so it leaves no audit
            // row — only the actual bulk write is recorded, the same way the
            // sibling transitions record theirs.
            if ($touchedSectionIds !== []) {
                $this->auditRecorder->record(
                    $actor,
                    AuditAction::SECTION_PLAN_AUTO_ASSIGNED,
                    AuditableType::SECTION_PLAN,
                    $planIds->first(),
                    null,
                    ['academic_term_id' => $term->id, 'curriculum_id' => $curriculumId, 'college' => $college, 'year_level' => $yearLevel, 'section_ids' => $touchedSectionIds],
                    null,
                    $context,
                );
            }

            return $touched;
        });
    }

    /**
     * Deliberately duplicates `SaveSectionPlan`'s identically-named private
     * guard rather than hoisting it into a shared trait: it is a handful of
     * lines, and each action on this workflow owns its ownership check.
     */
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

    private function findOrCreateFaculty(string $name): User
    {
        // The email is derived from a slug of $name, which strips
        // punctuation — two raw reference names that differ only in
        // punctuation/spacing (e.g. "COACH LORETO" vs "COACH. LORETO", the
        // same person named inconsistently across placements) collide on
        // the same slug. Looking up by that email, the actual unique
        // constraint, keeps this idempotent; looking up by the differing
        // $name would not.
        return User::firstOrCreate(
            ['email' => 'prof.'.Str::slug($name).'@grc.test'],
            [
                'name' => $name,
                'role' => UserRole::Faculty,
                'password' => 'password',
                'status' => UserStatus::Active,
            ],
        );
    }

    /**
     * A room named in historical curriculum reference data can now collide
     * with an already-scheduled section — rooms are shared campus-wide, so
     * this checks every college's booking in that room this term, not just
     * this curriculum's. `$pendingChanges` carries this same pass's not-yet-
     * saved day/time/modality so the check uses the section's final values.
     *
     * @param  array<string, mixed>  $pendingChanges
     */
    private function referenceRoomConflicts(Section $section, string $room, array $pendingChanges): bool
    {
        $modality = $pendingChanges['modality'] ?? $section->modality;

        $existing = Section::query()
            ->where('academic_term_id', $section->academic_term_id)
            ->where('room', $room)
            ->whereKeyNot($section->id)
            ->whereNotNull('schedule_days')
            ->get(['schedule_days', 'starts_at_time', 'ends_at_time', 'modality'])
            ->map(fn (Section $other): array => [
                'schedule_days' => $other->schedule_days,
                'starts_at_time' => $other->starts_at_time,
                'ends_at_time' => $other->ends_at_time,
                'modality' => $other->modality?->value,
            ])
            ->all();

        return $this->roomConflictDetector->hasConflict([
            'schedule_days' => $pendingChanges['schedule_days'] ?? $section->schedule_days,
            'starts_at_time' => $pendingChanges['starts_at_time'] ?? $section->starts_at_time,
            'ends_at_time' => $pendingChanges['ends_at_time'] ?? $section->ends_at_time,
            'modality' => $modality instanceof SectionModality ? $modality->value : null,
        ], $existing);
    }

    /**
     * Whether giving `$professorId` this section would double-book them
     * against any other section they already teach this term — any college,
     * any plan status. `$pendingChanges` carries this same pass's not-yet-
     * saved day/time so the check uses the section's final slot.
     *
     * @param  array<string, mixed>  $pendingChanges
     */
    private function referenceProfessorConflicts(Section $section, int $professorId, array $pendingChanges): bool
    {
        $existing = array_values(
            Section::query()
                ->where('academic_term_id', $section->academic_term_id)
                ->where('professor_id', $professorId)
                ->whereKeyNot($section->id)
                ->whereNotNull('schedule_days')
                ->get(['schedule_days', 'starts_at_time', 'ends_at_time'])
                ->map(fn (Section $other): array => [
                    'schedule_days' => $other->schedule_days,
                    'starts_at_time' => $other->starts_at_time,
                    'ends_at_time' => $other->ends_at_time,
                ])
                ->all(),
        );

        return $this->conflictDetector->hasConflict([
            'schedule_days' => $pendingChanges['schedule_days'] ?? $section->schedule_days,
            'starts_at_time' => $pendingChanges['starts_at_time'] ?? $section->starts_at_time,
            'ends_at_time' => $pendingChanges['ends_at_time'] ?? $section->ends_at_time,
        ], $existing);
    }

    private function hasReferenceTime(?CurriculumSubject $placement): bool
    {
        return $placement?->reference_start_time !== null && $placement->reference_end_time !== null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function availableFallbackTime(Section $section, ?string $scheduleDays): array
    {
        if ($section->section_plan_id === null || $scheduleDays === null) {
            return self::FALLBACK_TIME_SLOTS[0];
        }

        $existingSlots = array_values(
            Section::query()
                ->where('section_plan_id', $section->section_plan_id)
                ->where('section_code', $section->section_code)
                ->whereKeyNot($section->id)
                ->get(['schedule_days', 'starts_at_time', 'ends_at_time'])
                ->map(fn (Section $other): array => [
                    'schedule_days' => $other->schedule_days,
                    'starts_at_time' => $other->starts_at_time,
                    'ends_at_time' => $other->ends_at_time,
                ])
                ->all(),
        );

        foreach (self::FALLBACK_TIME_SLOTS as [$startsAt, $endsAt]) {
            if (! $this->conflictDetector->hasConflict([
                'schedule_days' => $scheduleDays,
                'starts_at_time' => $startsAt,
                'ends_at_time' => $endsAt,
            ], $existingSlots)) {
                return [$startsAt, $endsAt];
            }
        }

        // No available deterministic fallback exists. Keep the documented
        // common default; schedule review will still surface the genuine
        // conflict rather than silently altering a recorded day.
        return self::FALLBACK_TIME_SLOTS[0];
    }
}
