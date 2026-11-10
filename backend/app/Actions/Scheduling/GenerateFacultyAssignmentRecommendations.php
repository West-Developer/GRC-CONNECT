<?php

namespace App\Actions\Scheduling;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\FacultyLoadPlanner;
use App\Domain\Scheduling\RoomConflictDetector;
use App\Domain\Scheduling\ScheduleDayParser;
use App\Domain\Scheduling\ScheduleGenerationWarningType;
use App\Domain\Scheduling\SectionConflictDetector;
use App\Domain\Scheduling\SectionModality;
use App\Models\AcademicTermSectionPlan;
use App\Models\CurriculumSubject;
use App\Models\FacultyAssignmentRecommendation;
use App\Models\FacultyAvailability;
use App\Models\FacultyCurriculumSubjectPreference;
use App\Models\FacultySpecialization;
use App\Models\FacultySubjectPreference;
use App\Models\FacultyTeachingHistory;
use App\Models\RoomCatalogEntry;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\User;

/** Builds editable, persisted faculty recommendations from declared inputs. */
final class GenerateFacultyAssignmentRecommendations
{
    public function __construct(
        private readonly FacultyLoadPlanner $planner,
        private readonly ScheduleDayParser $dayParser,
        private readonly SectionConflictDetector $conflictDetector,
        private readonly RoomConflictDetector $roomConflictDetector,
    ) {}

    /** @return list<array{type: string, message: string, entity_id: ?int}> */
    public function execute(ScheduleGenerationRun $run): array
    {
        $run->loadMissing('academicTerm');
        $isDraftWorkflow = $run->academicTerm->collegeWorkflows()
            ->where('college', $run->college)
            ->where('stage', AcademicTermCollegeWorkflowStage::SchedulePreparation)
            ->exists();

        if ($isDraftWorkflow) {
            AcademicTermSectionPlan::query()
                ->where('academic_term_id', $run->academic_term_id)
                ->where('college', $run->college)
                ->where('status', SectionPlanStatus::Submitted)
                ->update([
                    'status' => SectionPlanStatus::Draft,
                    'submitted_by' => null,
                    'submitted_at' => null,
                ]);
        }

        $sections = Section::query()
            ->with(['sectionPlan', 'subject'])
            ->where('academic_term_id', $run->academic_term_id)
            ->whereHas('sectionPlan', fn ($plans) => $plans->where('college', $run->college)->where('status', 'draft'))
            ->orderBy('id')
            ->get();
        if ($sections->isEmpty()) {
            return [[
                'type' => ScheduleGenerationWarningType::NoDraftSections->value,
                'message' => 'No draft sections are available for faculty loading.',
                'entity_id' => null,
            ]];
        }
        $warnings = [];
        foreach ($sections as $section) {
            $this->fillReferenceSchedule($section);
            $roomWarning = $this->assignConfiguredRoom($run, $section);
            if ($roomWarning !== null) {
                $warnings[] = $roomWarning;
            }
        }

        $faculty = User::query()
            ->where('role', UserRole::Faculty->value)
            ->where('status', UserStatus::Active->value)
            ->where('college', $run->college)
            ->orderBy('id')
            ->get(['id']);
        $legacyPreferences = FacultySubjectPreference::query()
            ->where('academic_term_id', $run->academic_term_id)
            ->whereIn('professor_id', $faculty->pluck('id'))
            ->get()
            ->keyBy(fn (FacultySubjectPreference $preference): string => $preference->professor_id.':'.$preference->subject_id);
        $semester = $run->academicTerm->semester;
        $reusablePreferences = FacultyCurriculumSubjectPreference::query()
            ->where('semester', $semester)
            ->whereIn('professor_id', $faculty->pluck('id'))
            ->get()
            ->keyBy(fn (FacultyCurriculumSubjectPreference $preference): string => $preference->professor_id.':'.$preference->curriculum_id.':'.$preference->subject_id);
        $teachingHistory = FacultyTeachingHistory::query()
            ->where('semester', $semester)
            ->whereIn('professor_id', $faculty->pluck('id'))
            ->get()
            ->keyBy(fn (FacultyTeachingHistory $history): string => $history->professor_id.':'.$history->curriculum_id.':'.$history->subject_id);
        $availabilities = FacultyAvailability::query()
            ->whereIn('professor_id', $faculty->pluck('id'))
            ->get()
            ->groupBy('professor_id');
        $specializations = FacultySpecialization::query()
            ->whereIn('professor_id', $faculty->pluck('id'))
            ->whereIn('subject_id', $sections->pluck('subject_id')->unique())
            ->get()
            ->keyBy(fn (FacultySpecialization $specialization): string => $specialization->professor_id.':'.$specialization->subject_id);

        // Seeded from EVERY already-assigned section in the term, not just
        // this run's own college/draft slice. A professor's commitments in
        // another college — or in a same-college plan already submitted —
        // are just as real a double-booking, and scoping this to `$sections`
        // made them invisible to the conflict check below.
        $assignedUnits = [];
        $assignedSlots = [];
        $termAssignments = Section::query()
            ->with('subject:id,units')
            ->where('academic_term_id', $run->academic_term_id)
            ->whereNotNull('professor_id')
            ->get(['id', 'professor_id', 'subject_id', 'schedule_days', 'starts_at_time', 'ends_at_time']);
        foreach ($termAssignments as $assigned) {
            $assignedUnits[$assigned->professor_id] = ($assignedUnits[$assigned->professor_id] ?? 0)
                + (float) ($assigned->subject?->units ?? 0);
            $assignedSlots[$assigned->professor_id][] = $this->slot($assigned);
        }

        foreach ($sections as $section) {
            if ($section->professor_id !== null) {
                FacultyAssignmentRecommendation::updateOrCreate(
                    ['schedule_generation_run_id' => $run->id, 'section_id' => $section->id],
                    [
                        'recommended_professor_id' => $section->professor_id,
                        'preference_rank' => null,
                        'specialization_match' => null,
                        'availability_match' => false,
                        'conflict_free' => true,
                        'rationale' => ['existing_draft_assignment'],
                    ],
                );

                continue;
            }
            $candidates = [];
            $curriculumId = $section->sectionPlan?->curriculum_id;
            foreach ($faculty as $member) {
                $preference = $curriculumId === null
                    ? null
                    : $reusablePreferences->get($member->id.':'.$curriculumId.':'.$section->subject_id);
                $preference ??= $legacyPreferences->get($member->id.':'.$section->subject_id);
                if (! $preference instanceof FacultyCurriculumSubjectPreference && ! $preference instanceof FacultySubjectPreference) {
                    continue;
                }
                $history = $curriculumId === null
                    ? null
                    : $teachingHistory->get($member->id.':'.$curriculumId.':'.$section->subject_id);
                $specializationMatch = $specializations->get($member->id.':'.$section->subject_id)?->proficiency;
                $availabilityMatch = $this->isAvailable(
                    $section,
                    $availabilities->get($member->id)?->all() ?? [],
                );
                $conflictFree = ! $this->conflictDetector->hasConflict(
                    $this->slot($section),
                    $assignedSlots[$member->id] ?? [],
                );
                $candidates[] = [
                    'id' => $member->id,
                    'preference_rank' => $preference->rank,
                    'specialization_match' => $specializationMatch,
                    'teaching_history_evidence' => $history instanceof FacultyTeachingHistory ? $history->evidence_count : 0,
                    'availability_match' => $availabilityMatch,
                    'conflict_free' => $conflictFree,
                    'assigned_units' => $assignedUnits[$member->id] ?? 0,
                ];
            }
            $choice = $this->planner->choose($candidates);
            $selectedId = $choice['professor_id'];
            $selected = collect($candidates)->firstWhere('id', $selectedId);

            FacultyAssignmentRecommendation::updateOrCreate(
                ['schedule_generation_run_id' => $run->id, 'section_id' => $section->id],
                [
                    'recommended_professor_id' => $selectedId,
                    'preference_rank' => $selected['preference_rank'] ?? null,
                    'specialization_match' => ($selected['specialization_match'] ?? null)?->value,
                    'availability_match' => $selected['availability_match'] ?? false,
                    'conflict_free' => $selected['conflict_free'] ?? false,
                    'rationale' => $choice['rationale'],
                ],
            );

            if ($selectedId === null) {
                $warnings[] = [
                    'type' => ScheduleGenerationWarningType::FacultyUnavailable->value,
                    'message' => "No available preferred faculty could be recommended for section {$section->id}.",
                    'entity_id' => $section->id,
                ];

                continue;
            }
            if ($section->professor_id === null) {
                $section->update(['professor_id' => $selectedId]);
                $assignedUnits[$selectedId] = ($assignedUnits[$selectedId] ?? 0) + (float) $section->subject->units;
                $assignedSlots[$selectedId][] = $this->slot($section);
            }
        }

        return array_values(array_unique($warnings, SORT_REGULAR));
    }

    private function fillReferenceSchedule(Section $section): void
    {
        if ($section->sectionPlan === null) {
            return;
        }
        $placement = CurriculumSubject::query()
            ->where('curriculum_id', $section->sectionPlan->curriculum_id)
            ->where('subject_id', $section->subject_id)
            ->first();
        if ($placement === null) {
            return;
        }
        // GRC's schedule taxonomy only has three modalities. A legacy
        // "ONLINE" reference (from before the current Hyflex A/B split)
        // names a placeholder room, never a real one, so it's excluded
        // here — the normal room-catalog assignment fills a real room
        // instead, once the fallback below resolves a concrete modality.
        $isLegacyOnlineReference = $placement->reference_modality !== null
            && SectionModality::tryFrom(strtolower(str_replace(' ', '_', $placement->reference_modality))) === null;

        $normalizedRefRoom = $this->normalizeRoomName($placement->reference_room);

        $changes = [];
        foreach ([
            'schedule_days' => $placement->reference_day,
            'room' => $isLegacyOnlineReference ? null : $normalizedRefRoom,
        ] as $key => $value) {
            if ($section->{$key} === null && $value !== null) {
                $changes[$key] = $value;
            }
        }
        // A handful of placements have a real reference_day but no
        // recorded time at all — genuinely missing source data, not a
        // parsing gap. Per product direction, default to the most common
        // real time block already present in the seeded roster rather
        // than staying unresolved.
        if ($section->starts_at_time === null && $placement->reference_day !== null) {
            $changes['starts_at_time'] = $placement->reference_start_time ?? '07:30:00';
        }
        if ($section->ends_at_time === null && $placement->reference_day !== null) {
            $changes['ends_at_time'] = $placement->reference_end_time ?? '10:30:00';
        }
        if ($section->modality === null) {
            if ($placement->reference_modality !== null) {
                // An unmapped legacy value (only ever "ONLINE" in the
                // seeded roster) has no direct equivalent, so it falls back
                // to Hyflex A — week 1 face-to-face, alternating — rather
                // than staying an unresolved gap. A Program Chair can
                // still change it to Hyflex B or F2F like any other
                // section field.
                $changes['modality'] = SectionModality::tryFrom(strtolower(str_replace(' ', '_', $placement->reference_modality)))
                    ?? SectionModality::HyflexA;
            } elseif ($placement->reference_day !== null) {
                // Only CCS's rows in the reference CSV carry a modality at
                // all — COE/COA/CBAE's rows have a real day/time but leave
                // the modality column blank outright. Per product
                // direction, that absence defaults to ordinary
                // face-to-face, the least assumption-laden option, rather
                // than staying unresolved. A placement with no reference
                // data at all (no day either) has nothing to default from.
                $changes['modality'] = SectionModality::FaceToFace;
            }
        }
        if ($changes !== []) {
            $section->update($changes);
            $section->refresh();
        }
    }

    /**
     * Choose only from fully configured local room metadata. Missing capacity,
     * type, or subject requirements intentionally leave the room editable and
     * unassigned rather than inventing a physical placement.
     *
     * The candidate room's existing bookings are read fresh from the
     * database rather than from the run's own same-college section list —
     * rooms are shared campus-wide, so a college generating its schedule
     * must also see every other college's booking in that room this term,
     * not just its own draft plan's.
     *
     * @return ?array{type: string, message: string, entity_id: int}
     */
    private function assignConfiguredRoom(ScheduleGenerationRun $run, Section $section): ?array
    {
        $preassignedRoom = $this->normalizeRoomName($section->room);
        if ($preassignedRoom !== null && $section->room !== $preassignedRoom) {
            $section->update(['room' => $preassignedRoom]);
            $section->refresh();
        }

        if ($preassignedRoom !== null) {
            $matchedRoom = RoomCatalogEntry::query()
                ->where('college', $run->college)
                ->where(function ($query) use ($preassignedRoom): void {
                    $query->where('name', $preassignedRoom)
                        ->orWhereRaw('UPPER(name) = ?', [strtoupper($preassignedRoom)]);
                })
                ->whereNotNull('capacity')
                ->whereNotNull('room_type')
                ->first();

            if ($matchedRoom !== null) {
                if ($section->room !== $matchedRoom->name) {
                    $section->update(['room' => $matchedRoom->name]);
                    $section->refresh();
                    $preassignedRoom = $matchedRoom->name;
                }

                if (! $this->roomIsOccupied($section, $matchedRoom->name)) {
                    return null;
                }
            } else {
                // The preassigned room is not in the configured catalog (e.g. unknown room code).
                // Rather than abandoning room placement, clear it so the catalog-matching fallback
                // below can assign an available room of the subject's required room type.
                $section->update(['room' => null]);
                $section->refresh();
                $preassignedRoom = null;
            }
        }

        if ($section->subject->room_requirement === null) {
            return [
                'type' => $preassignedRoom === null
                    ? ScheduleGenerationWarningType::RoomRequirementMissing->value
                    : ScheduleGenerationWarningType::RoomConflictUnresolved->value,
                'message' => $preassignedRoom === null
                    ? "Subject room requirement is not configured for section {$section->id}; room assignment remains manual."
                    : "Section {$section->id} double-books room {$preassignedRoom}, and its subject has no room requirement to pick a replacement from; resolve manually.",
                'entity_id' => $section->id,
            ];
        }
        if ($section->schedule_days === null || $section->starts_at_time === null || $section->ends_at_time === null || $section->modality === null) {
            return [
                'type' => ScheduleGenerationWarningType::ScheduleMetadataIncomplete->value,
                'message' => "Schedule metadata is incomplete for section {$section->id}; room assignment remains manual.",
                'entity_id' => $section->id,
            ];
        }
        // Every overlap rule compares `HH:MM:SS` as strings, so an interval
        // whose end precedes its start matches nothing and would be handed a
        // room that is in fact occupied. Refuse to place it at all.
        if ($section->ends_at_time <= $section->starts_at_time) {
            return [
                'type' => ScheduleGenerationWarningType::ScheduleMetadataIncomplete->value,
                'message' => "Section {$section->id} ends at or before it starts ({$section->starts_at_time}–{$section->ends_at_time}); fix the time range before a room can be assigned.",
                'entity_id' => $section->id,
            ];
        }

        $rooms = RoomCatalogEntry::query()
            ->where('college', $run->college)
            ->where('room_type', $section->subject->room_requirement)
            ->whereNotNull('capacity')
            ->orderBy('name')
            ->get();
        foreach ($rooms as $room) {
            if ($room->capacity < $section->capacity) {
                continue;
            }
            if (! $this->roomIsOccupied($section, $room->name)) {
                $section->update(['room' => $room->name]);
                $section->refresh();

                return null;
            }
        }

        if ($preassignedRoom !== null) {
            return [
                'type' => ScheduleGenerationWarningType::RoomConflictUnresolved->value,
                'message' => "Section {$section->id} double-books room {$preassignedRoom} and no free {$section->subject->room_requirement} room can replace it; resolve manually.",
                'entity_id' => $section->id,
            ];
        }

        return [
            'type' => ScheduleGenerationWarningType::NoRoomAvailable->value,
            'message' => "No configured {$section->subject->room_requirement} room can accommodate section {$section->id}; assign a room manually.",
            'entity_id' => $section->id,
        ];
    }

    /**
     * Normalizes room aliases and whitespace differences (e.g. LAB1 -> LAB 1, PE-ROOM -> PE ROOM)
     * so reference data reliably matches room catalog entries.
     */
    private function normalizeRoomName(?string $room): ?string
    {
        if ($room === null) {
            return null;
        }

        $trimmed = trim($room);
        if ($trimmed === '' || strtoupper($trimmed) === 'ONLINE') {
            return null;
        }

        $clean = strtoupper((string) (preg_replace('/\s+/', ' ', str_replace('-', ' ', $trimmed)) ?? $trimmed));

        if (preg_match('/^LAB\s*(\d+)$/i', $clean, $matches)) {
            return 'LAB '.$matches[1];
        }

        if (preg_match('/^COM\s*LAB\s*(\d+)$/i', $clean, $matches)) {
            return 'COM LAB '.$matches[1];
        }

        if ($clean === 'SCIE LAB') {
            return 'SCI LAB';
        }

        if (preg_match('/^PE\s*ROOM\s*(\d+)$/i', $clean, $matches)) {
            return 'PE ROOM '.$matches[1];
        }

        return $clean;
    }

    /**
     * Whether placing `$section` in `$roomName` would collide with a booking
     * already in that room this term.
     *
     * Deliberately NOT scoped to one college or to the run's own section
     * list — a shared room's true occupancy spans every college. Each
     * `$section->update()` persists immediately (no surrounding
     * transaction), so a room claimed earlier in this same run is already
     * visible to this fresh query.
     *
     * An existing booking with a NULL modality is treated as ordinary
     * face-to-face rather than skipped: `RoomConflictDetector` only judges
     * rows that declare a physical-week modality, so passing the null
     * through would silently report a physically-occupied room as free.
     */
    private function roomIsOccupied(Section $section, string $roomName): bool
    {
        if ($section->schedule_days === null || $section->starts_at_time === null || $section->ends_at_time === null) {
            return false;
        }

        $existing = Section::query()
            ->where('academic_term_id', $section->academic_term_id)
            ->where('room', $roomName)
            ->whereKeyNot($section->id)
            ->whereNotNull('schedule_days')
            ->get(['id', 'schedule_days', 'starts_at_time', 'ends_at_time', 'modality'])
            ->map(fn (Section $other): array => [
                'schedule_days' => $other->schedule_days,
                'starts_at_time' => $other->starts_at_time,
                'ends_at_time' => $other->ends_at_time,
                'modality' => $other->modality?->value ?? SectionModality::FaceToFace->value,
            ])->values()->all();

        return $this->roomConflictDetector->hasConflict([
            'schedule_days' => $section->schedule_days,
            'starts_at_time' => $section->starts_at_time,
            'ends_at_time' => $section->ends_at_time,
            'modality' => ($section->modality ?? SectionModality::FaceToFace)->value,
        ], $existing);
    }

    /** @param list<FacultyAvailability> $availability */
    private function isAvailable(Section $section, array $availability): bool
    {
        if ($section->schedule_days === null || $section->starts_at_time === null || $section->ends_at_time === null) {
            return false;
        }
        $neededDays = $this->dayParser->parse($section->schedule_days);
        if ($neededDays === []) {
            return false;
        }
        foreach ($neededDays as $day) {
            $covered = collect($availability)->contains(fn (FacultyAvailability $window): bool => $window->day_of_week === $day &&
                $window->starts_at_time <= $section->starts_at_time &&
                $window->ends_at_time >= $section->ends_at_time,
            );
            if (! $covered) {
                return false;
            }
        }

        return true;
    }

    /** @return array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string} */
    private function slot(Section $section): array
    {
        return [
            'schedule_days' => $section->schedule_days,
            'starts_at_time' => $section->starts_at_time,
            'ends_at_time' => $section->ends_at_time,
        ];
    }
}
