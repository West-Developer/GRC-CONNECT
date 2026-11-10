<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class PairSubjectsMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    /**
     * `--step=3`, not 2: `2026_08_29_000001_add_status_to_faculty_specializations_table`
     * and `2026_08_27_000003_normalize_student_name_casing` are now the most
     * recently applied migrations, so they have to come off too before this
     * migration (`add_paired_subject_id_to_subjects`) can.
     */
    public function test_the_migration_pairs_lecture_and_laboratory_subjects_by_code_and_title(): void
    {
        $this->rollbackThrough('2026_08_27_000002_add_paired_subject_id_to_subjects');
        self::assertFalse(Schema::hasColumn('subjects', 'paired_subject_id'));

        $insert = fn (array $overrides) => DB::table('subjects')->insertGetId(array_merge([
            'units' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        $prog1 = $insert(['code' => 'PROG1', 'college' => 'ccs', 'title' => 'Computer Programming 1 LEC']);
        $prog1L = $insert(['code' => 'PROG1L', 'college' => 'ccs', 'title' => 'Computer Programming 1 LAB']);

        // A code ending in "L" with no LEC/LAB-titled sibling at all must
        // never be treated as a lab component.
        $komfil = $insert(['code' => 'KOMFIL', 'college' => 'ccs', 'title' => 'Komunikasyon at Pananaliksik']);

        // A lab-only subject with no matching code+"L" LEC counterpart
        // anywhere stays unpaired.
        $itplus3 = $insert(['code' => 'ITPLUS3', 'college' => 'ccs', 'title' => 'Office Productivity Tools LAB']);

        // Two genuinely different code-pairs that happen to share the exact
        // same title text within the same college must each resolve to
        // their own correct partner via code, not cross-pair.
        $dstruct = $insert(['code' => 'DSTRUCT', 'college' => 'ccs', 'title' => 'Data Structures and Algorithms LEC']);
        $dstructL = $insert(['code' => 'DSTRUCTL', 'college' => 'ccs', 'title' => 'Data Structures and Algorithms LAB']);
        $dstruc = $insert(['code' => 'DSTRUC', 'college' => 'ccs', 'title' => 'Data Structures and Algorithms LEC']);
        $dstrucL = $insert(['code' => 'DSTRUCL', 'college' => 'ccs', 'title' => 'Data Structures and Algorithms LAB']);

        // A matching code+"L" pair in a DIFFERENT college must never pair
        // across the college boundary.
        $crossLec = $insert(['code' => 'XLEC', 'college' => 'ccs', 'title' => 'Cross College Subject LEC']);
        $crossLabOtherCollege = $insert(['code' => 'XLECL', 'college' => 'coe', 'title' => 'Cross College Subject LAB']);

        $this->artisan('migrate')->assertExitCode(0);

        $pairedOf = fn (int $id) => DB::table('subjects')->where('id', $id)->value('paired_subject_id');

        self::assertSame($prog1L, $pairedOf($prog1));
        self::assertSame($prog1, $pairedOf($prog1L));

        self::assertNull($pairedOf($komfil));
        self::assertNull($pairedOf($itplus3));

        self::assertSame($dstructL, $pairedOf($dstruct));
        self::assertSame($dstruct, $pairedOf($dstructL));
        self::assertSame($dstrucL, $pairedOf($dstruc));
        self::assertSame($dstruc, $pairedOf($dstrucL));

        self::assertNull($pairedOf($crossLec));
        self::assertNull($pairedOf($crossLabOtherCollege));
    }
}
