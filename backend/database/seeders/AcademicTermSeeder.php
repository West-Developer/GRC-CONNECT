<?php

namespace Database\Seeders;

use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds seven terms spanning 2023-2024 through 2026-2027 1st semester, the
 * latter left `semester_closed` and tracked as the current term —
 * "the start of the cycle": a semester that has just concluded and needs the
 * Registrar to archive it before the next one can be opened, matching the
 * real Registrar workflow end to end (Archive current semester → name the
 * next school year/semester → Draft → Program Chair publishes a schedule →
 * Registrar opens enrollment).
 *
 * This reverts an earlier amendment (see `docs/testing/SEEDED_IDENTITIES.md`)
 * that pre-seeded 2026-2027 2nd semester as an already-`semester_ongoing`
 * term with published sections, for a different priority ("let me test
 * enrolling right now"). `SectionSeeder` and
 * `DemoEnrollmentSeeder::seedRegularBlocks()` both already guard on a
 * `semester_ongoing` term existing and no-op cleanly when one doesn't — no
 * change was needed in either for this reversion.
 *
 * A Registrar Head reaches an enrollable term through the ordinary
 * archive-and-create-next flow at any time; nothing here bypasses that
 * pipeline.
 */
final class AcademicTermSeeder extends Seeder
{
    private const CURRENT_SCHOOL_YEAR = '2026-2027';

    private const CURRENT_SEMESTER = '1st';

    /**
     * @var list<array{school_year: string, semester: string, status: AcademicTermStatus}>
     */
    private const TERMS = [
        ['school_year' => '2017-2018', 'semester' => '1st', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2017-2018', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2018-2019', 'semester' => '1st', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2018-2019', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2019-2020', 'semester' => '1st', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2019-2020', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2020-2021', 'semester' => '1st', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2020-2021', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2021-2022', 'semester' => '1st', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2021-2022', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2022-2023', 'semester' => '1st', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2022-2023', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2023-2024', 'semester' => '1st', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2023-2024', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2024-2025', 'semester' => '1st', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2024-2025', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2025-2026', 'semester' => '1st', 'status' => AcademicTermStatus::Archived],
        ['school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived],
        // The one current term a clean seed leaves behind — closed, tracked
        // as current, and genuinely needing the Registrar to archive it.
        ['school_year' => self::CURRENT_SCHOOL_YEAR, 'semester' => self::CURRENT_SEMESTER, 'status' => AcademicTermStatus::SemesterClosed],
    ];

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            foreach (self::TERMS as $term) {
                AcademicTerm::updateOrCreate(
                    ['school_year' => $term['school_year'], 'semester' => $term['semester']],
                    ['status' => $term['status']],
                );
            }

            // Keep the local archive deterministic without deleting any term
            // records. Older WIP rows (including a stray 2026-2027 2nd from
            // a prior seed shape) are retained as archived history so a
            // fresh manual-test run cannot accidentally expose a stale
            // current semester.
            $pairs = collect(self::TERMS)
                ->map(fn (array $term): string => "{$term['school_year']}|{$term['semester']}")
                ->all();
            AcademicTerm::query()
                ->get()
                ->filter(fn (AcademicTerm $term): bool => ! in_array(
                    "{$term->school_year}|{$term->semester}",
                    $pairs,
                    true,
                ))
                ->each(function (AcademicTerm $term): void {
                    $term->update([
                        'status' => AcademicTermStatus::Archived,
                        'closed_at' => $term->closed_at ?? now(),
                        'archived_at' => $term->archived_at ?? now(),
                    ]);
                });

            $currentTerm = AcademicTerm::query()
                ->where('school_year', self::CURRENT_SCHOOL_YEAR)
                ->where('semester', self::CURRENT_SEMESTER)
                ->sole();

            DB::table('academic_term_current_slots')
                ->where('id', 1)
                ->update(['academic_term_id' => $currentTerm->id, 'updated_at' => now()]);
        });
    }

    /**
     * Synthetic reference data must never reach a production-like environment.
     */
    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'AcademicTermSeeder may only run in the local or testing environment. '
                .'Refusing to seed synthetic term data into "'.app()->environment().'".',
            );
        }
    }
}
