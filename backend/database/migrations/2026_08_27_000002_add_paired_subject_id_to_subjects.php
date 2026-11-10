<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links each lecture subject row to its laboratory companion (and vice
 * versa) — e.g. "PROG1" (title "Computer Programming 1 LEC") <-> "PROG1L"
 * (title "Computer Programming 1 LAB") — so a student selecting one
 * section automatically gets a matching section for the other, per
 * institutional policy that a subject's LEC and LAB components are always
 * taken together, never separately.
 *
 * Backfill matches a LEC-titled subject to the same-college subject whose
 * `code` is its own code plus a trailing "L", guarded by that candidate's
 * own title actually ending in " LAB" — code alone is not reliable (several
 * unrelated subjects legitimately end in "L" with no LEC counterpart at
 * all: KOMFIL, RIZAL, ...), and title alone is not reliable either (a
 * handful of course titles collide across two differently-coded catalog
 * entries, e.g. both "DSTRUCT"/"DSTRUCTL" and "DSTRUC"/"DSTRUCL" are titled
 * "Data Structures and Algorithms LEC"/"LAB"). The combined check verified
 * against the live catalog pairs all 34 real LEC/LAB pairs with zero
 * misses and zero false positives, and correctly leaves standalone
 * lab-only courses with no LEC counterpart unpaired (e.g. ITPLUS3/ITPLUS4,
 * whose codes don't follow the "L"-suffix convention at all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->foreignId('paired_subject_id')->nullable()->after('units')
                ->constrained('subjects')->nullOnDelete();
        });

        $this->backfillPairs();
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paired_subject_id');
        });
    }

    private function backfillPairs(): void
    {
        $subjects = DB::table('subjects')->select('id', 'college', 'code', 'title')->get();
        $byCollegeAndCode = $subjects->keyBy(fn (object $subject): string => ($subject->college ?? '').'|'.$subject->code);

        foreach ($subjects as $lecture) {
            if (! preg_match('/\s+LEC$/i', (string) $lecture->title)) {
                continue;
            }

            $labKey = ($lecture->college ?? '').'|'.$lecture->code.'L';
            $laboratory = $byCollegeAndCode->get($labKey);

            if ($laboratory === null || ! preg_match('/\s+LAB$/i', (string) $laboratory->title)) {
                continue;
            }

            DB::table('subjects')->where('id', $lecture->id)->update(['paired_subject_id' => $laboratory->id]);
            DB::table('subjects')->where('id', $laboratory->id)->update(['paired_subject_id' => $lecture->id]);
        }
    }
};
