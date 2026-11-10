<?php

namespace App\Console\Commands;

use App\Domain\Scheduling\RoomConflictDetector;
use App\Domain\Scheduling\ScheduleDayParser;
use App\Models\AcademicTerm;
use App\Models\Section;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Read-only conflict audit for one term's generated schedule.
 *
 * Answers the three questions a Program Chair asks after generating:
 * is every room double-booking-free (campus-wide, not just within one
 * college), is every professor free of double-booking, and did every
 * section actually receive a room. Uses the same domain rules the write
 * path enforces — `RoomConflictDetector` (which treats a complementary
 * HyFlex A/B pair as legitimately sharing a room) and the day/time overlap
 * rule from `SectionConflictDetector`.
 */
final class AuditScheduleConflicts extends Command
{
    protected $signature = 'schedule:audit-conflicts
        {--term= : Academic term id (defaults to the current ongoing term)}
        {--limit=15 : Max example rows to print per finding}';

    protected $description = 'Audit a term for room double-bookings, professor double-bookings, and sections left without a room.';

    public function handle(RoomConflictDetector $roomConflicts, ScheduleDayParser $dayParser): int
    {
        $term = $this->resolveTerm();
        if ($term === null) {
            $this->error('No term found. Pass --term=<id>.');

            return self::FAILURE;
        }
        $limit = (int) $this->option('limit');

        $this->info("Auditing term {$term->id}: {$term->school_year} · {$term->semester} ({$term->status->value})");

        $sections = Section::query()
            ->with(['subject', 'professor', 'sectionPlan'])
            ->where('academic_term_id', $term->id)
            ->orderBy('id')
            ->get();

        $this->line('Total sections: '.$sections->count());
        if ($sections->isEmpty()) {
            return self::SUCCESS;
        }

        $failures = 0;
        $failures += $this->reportCompleteness($sections, $limit);
        $failures += $this->reportRoomConflicts($sections, $roomConflicts, $limit, $dayParser);
        $failures += $this->reportProfessorConflicts($sections, $dayParser, $limit);

        $this->newLine();
        if ($failures === 0) {
            $this->info('PASS — no room conflicts, no professor conflicts, every section fully scheduled.');

            return self::SUCCESS;
        }

        $this->warn("FOUND {$failures} finding group(s) above.");

        return self::FAILURE;
    }

    private function resolveTerm(): ?AcademicTerm
    {
        $id = $this->option('term');
        if ($id !== null) {
            return AcademicTerm::find((int) $id);
        }

        return AcademicTerm::query()->where('status', 'semester_ongoing')->first()
            ?? AcademicTerm::query()->latest('id')->first();
    }

    /** @param Collection<int, Section> $sections */
    private function reportCompleteness(Collection $sections, int $limit): int
    {
        $this->newLine();
        $this->line('--- Completeness ---');

        $missing = [
            'no room' => $sections->whereNull('room'),
            'no schedule_days' => $sections->whereNull('schedule_days'),
            'no start/end time' => $sections->filter(
                fn (Section $s): bool => $s->starts_at_time === null || $s->ends_at_time === null,
            ),
            'no modality' => $sections->whereNull('modality'),
            'no professor' => $sections->whereNull('professor_id'),
            // Every overlap rule compares HH:MM:SS as strings, so a
            // backwards interval matches nothing and hides real collisions.
            'end <= start' => $sections->filter(
                fn (Section $s): bool => $s->starts_at_time !== null
                    && $s->ends_at_time !== null
                    && $s->ends_at_time <= $s->starts_at_time,
            ),
        ];

        $findings = 0;
        foreach ($missing as $label => $rows) {
            if ($rows->isEmpty()) {
                $this->line(sprintf('  OK   %-20s 0', $label));

                continue;
            }
            $findings++;
            $this->warn(sprintf('  FAIL %-20s %d', $label, $rows->count()));
            foreach ($rows->take($limit) as $section) {
                $this->line(sprintf(
                    '         #%d %s %s (%s) requirement=%s',
                    $section->id,
                    $section->section_code,
                    $section->subject->code,
                    $section->sectionPlan?->college ?? 'no-plan',
                    $section->subject->room_requirement ?? 'null',
                ));
            }
            if ($rows->count() > $limit) {
                $this->line('         … '.($rows->count() - $limit).' more');
            }
        }

        return $findings;
    }

    /** @param Collection<int, Section> $sections */
    private function reportRoomConflicts(Collection $sections, RoomConflictDetector $detector, int $limit, ScheduleDayParser $dayParser): int
    {
        $this->newLine();
        $this->line('--- Room double-bookings (campus-wide) ---');

        $pairs = [];
        $excusedHyflex = 0;
        $unknownModality = [];
        /** @var Collection<string, Collection<int, Section>> $byRoom */
        $byRoom = $sections->filter(fn (Section $s): bool => $s->room !== null)->groupBy('room');

        foreach ($byRoom as $room => $roomSections) {
            $list = array_values($roomSections->all());
            $count = count($list);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];

                    // Raw physical overlap first: same room, shared day,
                    // overlapping time. `RoomConflictDetector` alone would
                    // silently pass a pair whose modality is null (it only
                    // judges rows that declare a physical-week modality), so
                    // that blind spot is surfaced separately instead of
                    // being reported as "no conflict".
                    if (! $this->slotsCollide($a, $b, $dayParser)) {
                        continue;
                    }

                    if ($a->modality === null || $b->modality === null) {
                        $unknownModality[] = [$room, $a, $b];

                        continue;
                    }

                    if ($detector->hasConflict($this->slot($a), [$this->slot($b)])) {
                        $pairs[] = [$room, $a, $b];
                    } else {
                        $excusedHyflex++;
                    }
                }
            }
        }

        if ($excusedHyflex > 0) {
            $this->line("  note {$excusedHyflex} overlapping pair(s) excused as complementary HyFlex A/B");
        }

        $findings = 0;

        if ($unknownModality !== []) {
            $findings++;
            $this->warn('  FAIL '.count($unknownModality).' overlapping pair(s) with a NULL modality — cannot be judged, treated as unresolved');
            foreach (array_slice($unknownModality, 0, $limit) as [$room, $a, $b]) {
                $this->line(sprintf(
                    '         %s: #%d %s/%s %s %s-%s [%s]  vs  #%d %s/%s [%s]',
                    $room,
                    $a->id, $a->section_code, $a->subject->code, $a->schedule_days,
                    substr((string) $a->starts_at_time, 0, 5), substr((string) $a->ends_at_time, 0, 5),
                    $a->modality?->value ?? 'NULL',
                    $b->id, $b->section_code, $b->subject->code,
                    $b->modality?->value ?? 'NULL',
                ));
            }
            if (count($unknownModality) > $limit) {
                $this->line('         … '.(count($unknownModality) - $limit).' more');
            }
        }

        if ($pairs === []) {
            $this->line('  OK   0 true room conflicts');

            return $findings;
        }

        $findings++;
        $this->warn('  FAIL '.count($pairs).' conflicting pair(s)');
        foreach (array_slice($pairs, 0, $limit) as [$room, $a, $b]) {
            $this->line(sprintf(
                '         %s: #%d %s/%s %s %s-%s [%s|%s]   vs  #%d %s/%s %s %s-%s [%s|%s]',
                $room,
                $a->id, $a->section_code, $a->subject->code, $a->schedule_days,
                substr((string) $a->starts_at_time, 0, 5), substr((string) $a->ends_at_time, 0, 5),
                $a->modality?->value ?? 'null', $a->sectionPlan?->college ?? '?',
                $b->id, $b->section_code, $b->subject->code, $b->schedule_days,
                substr((string) $b->starts_at_time, 0, 5), substr((string) $b->ends_at_time, 0, 5),
                $b->modality?->value ?? 'null', $b->sectionPlan?->college ?? '?',
            ));
        }
        if (count($pairs) > $limit) {
            $this->line('         … '.(count($pairs) - $limit).' more');
        }

        return $findings;
    }

    /** @param Collection<int, Section> $sections */
    private function reportProfessorConflicts(Collection $sections, ScheduleDayParser $dayParser, int $limit): int
    {
        $this->newLine();
        $this->line('--- Professor double-bookings (campus-wide) ---');

        $pairs = [];
        /** @var Collection<int, Collection<int, Section>> $byProfessor */
        $byProfessor = $sections->filter(fn (Section $s): bool => $s->professor_id !== null)->groupBy('professor_id');

        foreach ($byProfessor as $professorSections) {
            $list = array_values($professorSections->all());
            $count = count($list);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if ($this->slotsCollide($a, $b, $dayParser)) {
                        $pairs[] = [$a, $b];
                    }
                }
            }
        }

        if ($pairs === []) {
            $this->line('  OK   0 professor conflicts');

            return 0;
        }

        $this->warn('  FAIL '.count($pairs).' conflicting pair(s)');
        foreach (array_slice($pairs, 0, $limit) as [$a, $b]) {
            $this->line(sprintf(
                '         %s: #%d %s/%s %s %s-%s (%s)  vs  #%d %s/%s %s %s-%s (%s)',
                $a->professor?->name ?? ('prof#'.$a->professor_id),
                $a->id, $a->section_code, $a->subject->code, $a->schedule_days,
                substr((string) $a->starts_at_time, 0, 5), substr((string) $a->ends_at_time, 0, 5),
                $a->sectionPlan?->college ?? '?',
                $b->id, $b->section_code, $b->subject->code, $b->schedule_days,
                substr((string) $b->starts_at_time, 0, 5), substr((string) $b->ends_at_time, 0, 5),
                $b->sectionPlan?->college ?? '?',
            ));
        }
        if (count($pairs) > $limit) {
            $this->line('         … '.(count($pairs) - $limit).' more');
        }

        return 1;
    }

    private function slotsCollide(Section $a, Section $b, ScheduleDayParser $dayParser): bool
    {
        if ($a->schedule_days === null || $a->starts_at_time === null || $a->ends_at_time === null) {
            return false;
        }
        if ($b->schedule_days === null || $b->starts_at_time === null || $b->ends_at_time === null) {
            return false;
        }
        $shared = array_intersect(
            $dayParser->parse($a->schedule_days),
            $dayParser->parse($b->schedule_days),
        );
        if ($shared === []) {
            return false;
        }

        return $a->starts_at_time < $b->ends_at_time && $b->starts_at_time < $a->ends_at_time;
    }

    /** @return array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, modality: ?string} */
    private function slot(Section $section): array
    {
        return [
            'schedule_days' => $section->schedule_days,
            'starts_at_time' => $section->starts_at_time,
            'ends_at_time' => $section->ends_at_time,
            'modality' => $section->modality?->value,
        ];
    }
}
