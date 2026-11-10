<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Links each lecture subject to its laboratory companion (and back), because a subject's LEC and LAB
 * components are always taken together. The rule is the one the 2026-08-27 `paired_subject_id`
 * migration backfilled with: a LEC-titled subject pairs with the same-college subject whose code is
 * its own plus a trailing "L", provided that candidate's title ends in " LAB". That backfill only
 * reaches rows that existed when it ran, so a freshly seeded database needs this step.
 *
 * Idempotent: re-running changes nothing.
 */
final class SubjectPairingSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = Subject::query()->get(['id', 'college', 'code', 'title', 'paired_subject_id']);
        $byCollegeAndCode = $subjects->keyBy(fn (Subject $subject): string => ($subject->college?->value ?? '').'|'.$subject->code);

        foreach ($subjects as $lecture) {
            if (! preg_match('/\s+LEC$/i', (string) $lecture->title)) {
                continue;
            }

            $laboratory = $byCollegeAndCode->get(($lecture->college?->value ?? '').'|'.$lecture->code.'L');

            if ($laboratory === null || ! preg_match('/\s+LAB$/i', (string) $laboratory->title)) {
                continue;
            }

            Subject::query()->whereKey($lecture->id)->update(['paired_subject_id' => $laboratory->id]);
            Subject::query()->whereKey($laboratory->id)->update(['paired_subject_id' => $lecture->id]);
        }
    }
}
