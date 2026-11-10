<?php

/**
 * One-off data-population script (owner-requested, 2026-09-28): the Rooms →
 * Archive view was showing every room as "Empty" for terms 1-5 and 19-30
 * because those archived terms' sections have no room, day, or time at all
 * — not merely a missing room. This assigns a deterministic, conflict-free
 * room + day + time to every section in those terms that is still missing
 * one, mirroring the exact style already present in the real data (a single
 * weekday, one of the four existing time slots, `modality` left null,
 * `professor_id` left null).
 *
 * This is explicitly FILLER data, not a recovered historical record — the
 * owner confirmed this scope (all archived terms with gaps) before this
 * script was written. Idempotent: sections that already have a room are
 * left untouched and are counted as pre-existing occupancy so newly
 * assigned slots never collide with them.
 *
 * Run: php scripts/backfill_archived_section_rooms.php
 */

use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\RoomCatalog;
use App\Models\AcademicTerm;
use App\Models\Section;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// The only day + time values already used by real data (see inspection of
// term 5's populated sections) — kept identical so filler rows are not
// visually distinguishable from genuine ones.
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const SLOTS = [
    ['08:00:00', '10:00:00'],
    ['10:00:00', '12:00:00'],
    ['13:00:00', '15:00:00'],
    ['15:00:00', '17:00:00'],
];

$terms = AcademicTerm::where('status', 'archived')->orderBy('id')->get(['id', 'school_year', 'semester']);

$totalAssigned = 0;

foreach ($terms as $term) {
    $gapSections = Section::where('academic_term_id', $term->id)
        ->whereNull('room')
        ->with('subject:id,college')
        ->orderBy('id')
        ->get(['id', 'subject_id']);

    if ($gapSections->isEmpty()) {
        continue;
    }

    // Seed the occupancy map from sections that already have a room+day in
    // this term, so newly assigned slots never double-book a real one.
    /** @var array<string, true> $used key: "room|day|start" */
    $used = [];
    foreach (
        Section::where('academic_term_id', $term->id)
            ->whereNotNull('room')
            ->whereNotNull('schedule_days')
            ->whereNotNull('starts_at_time')
            ->get(['room', 'schedule_days', 'starts_at_time']) as $existing
    ) {
        $used["{$existing->room}|{$existing->schedule_days}|{$existing->starts_at_time}"] = true;
    }

    $cursorByCollege = [];
    $assignedThisTerm = 0;

    DB::transaction(function () use ($gapSections, &$used, &$cursorByCollege, &$assignedThisTerm) {
        foreach ($gapSections as $section) {
            $college = $section->subject?->college ?? CollegeCode::Ccs;
            $rooms = RoomCatalog::forCollege($college);
            if ($rooms === []) {
                $rooms = RoomCatalog::forCollege(CollegeCode::Ccs);
            }

            $key = $college->value;
            $cursor = $cursorByCollege[$key] ?? 0;
            $slotCount = count($rooms) * count(DAYS) * count(SLOTS);

            $placed = false;
            for ($i = 0; $i < $slotCount; $i++) {
                $index = ($cursor + $i) % $slotCount;
                $room = $rooms[intdiv($index, count(DAYS) * count(SLOTS))];
                $dayIndex = intdiv($index % (count(DAYS) * count(SLOTS)), count(SLOTS));
                $slotIndex = $index % count(SLOTS);
                $day = DAYS[$dayIndex];
                [$start, $end] = SLOTS[$slotIndex];
                $mapKey = "{$room}|{$day}|{$start}";

                if (isset($used[$mapKey])) {
                    continue;
                }

                $section->update([
                    'room' => $room,
                    'schedule_days' => $day,
                    'starts_at_time' => $start,
                    'ends_at_time' => $end,
                ]);
                $used[$mapKey] = true;
                $cursorByCollege[$key] = $index + 1;
                $assignedThisTerm++;
                $placed = true;
                break;
            }

            if (! $placed) {
                // Every slot for this college is exhausted in this term —
                // leave the section as-is rather than double-booking.
                fwrite(STDERR, "  WARNING: no free slot for section {$section->id} (college {$key})\n");
            }
        }
    });

    echo "Term {$term->id} {$term->school_year} {$term->semester}: assigned {$assignedThisTerm} of {$gapSections->count()} gap sections\n";
    $totalAssigned += $assignedThisTerm;
}

echo "\nTotal sections assigned a room: {$totalAssigned}\n";
