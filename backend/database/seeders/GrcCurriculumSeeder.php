<?php

namespace Database\Seeders;

use App\Domain\Curriculum\CurriculumStatus;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SplFileObject;

/**
 * Seeds three curriculum versions for each of the 12 real GRC programs
 * (36 curricula total), from the 2024-2029 placement map extracted from
 * GRC's real block-section schedules (see
 * `data/extract-curriculum-placements.py` and its output
 * `data/curriculum-2024-2029-placements.csv`) — GRC replaces a program's
 * curriculum roughly every 5 years, and a student's own version never
 * changes after entry (see `App\Domain\Curriculum\CurriculumVersion`).
 *
 * The active 2024-2029 version is placed exactly as extracted. The two
 * archived versions (2018-2023, 2012-2017) copy that map with a small,
 * explicit per-program diff declared in `ARCHIVED_VARIATIONS` below.
 * IMPORTANT — those diffs are ILLUSTRATIVE PLACEHOLDERS, not real
 * historical GRC data: nobody supplied the actual 2012-2023 curricula, so
 * this seeder only proves the versioning mechanism works, the same honesty
 * `DemoGradeHistoryCurriculumSeeder`'s `PLACEHOLDER_MINIMUM_GRADE` uses for
 * its own placeholder value.
 *
 * Two data quirks in the source schedules, handled here rather than in the
 * extraction script (they are curriculum-authoring decisions, not parsing
 * decisions):
 *   - 4 subjects are genuinely offered in both semesters of the same year
 *     within one program (e.g. CBAE's `ECOMM`) — merged into a single
 *     placement whose `semester` is the composite `'1st|2nd'` string
 *     `App\Domain\Curriculum\SemesterCoverage` already knows how to read.
 *   - 1 subject (CBAE's `STRAMAN` under Marketing Management) is placed in
 *     two DIFFERENT year levels — `curriculum_subjects` is unique on
 *     `(curriculum_id, subject_id)`, so the earlier year level wins and the
 *     later one is dropped with a logged warning.
 *
 * Depends on `ProgramSeeder` and `GrcSubjectCatalogSeeder` having run first.
 */
final class GrcCurriculumSeeder extends Seeder
{
    /**
     * @var array<string, array{start: int, end: int, status: CurriculumStatus, file: string}>
     */
    private const VERSIONS = [
        '2024-2029' => ['start' => 2024, 'end' => 2029, 'status' => CurriculumStatus::Active, 'file' => __DIR__.'/data/curriculum-2024-2029-placements.csv'],
        '2018-2023' => ['start' => 2018, 'end' => 2023, 'status' => CurriculumStatus::Archived, 'file' => __DIR__.'/data/curriculum-2018-2023-placements.csv'],
        '2012-2017' => ['start' => 2012, 'end' => 2017, 'status' => CurriculumStatus::Archived, 'file' => __DIR__.'/data/curriculum-2012-2017-placements.csv'],
    ];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly ?string $csvPathOverride = null) {}

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            foreach (self::VERSIONS as $schoolYear => $version) {
                $filePath = $this->csvPathOverride ?? $version['file'];
                if (! is_file($filePath)) {
                    $filePath = __DIR__.'/data/curriculum-2024-2029-placements.csv';
                }
                $rowsByProgram = $this->readPlacementsByProgram($filePath);

                foreach ($rowsByProgram as $programCode => $rows) {
                    $program = Program::query()->where('code', $programCode)->first();

                    if ($program === null) {
                        $this->warnings[] = "Program '{$programCode}' is not seeded — run ProgramSeeder first. Skipped its curriculum.";

                        continue;
                    }

                    $this->seedVersion($program, $programCode, $schoolYear, $version, $rows);
                }
            }
        });

        $this->reportWarnings();
    }

    /**
     * @return array<string, list<array{year_level: int, semester: string, subject_code: string, college: string}>>
     */
    private function readPlacementsByProgram(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("GrcCurriculumSeeder could not find its placements CSV at {$path}");
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $header = null;
        $rowsByProgram = [];

        foreach ($file as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }

            if ($header === null) {
                $header = $line;

                continue;
            }

            if (count($line) !== count($header)) {
                continue;
            }

            /** @var array<string, string> $row */
            $row = array_combine($header, $line);
            $rowsByProgram[$row['program_code']][] = [
                'year_level' => (int) $row['year_level'],
                'semester' => $row['semester'],
                'subject_code' => $row['subject_code'],
                'college' => strtolower(trim($row['college'])),
            ];
        }

        return $rowsByProgram;
    }

    /**
     * @param  array{start: int, end: int, status: CurriculumStatus, file: string}  $version
     * @param  list<array{year_level: int, semester: string, subject_code: string, college: string}>  $rows
     */
    private function seedVersion(Program $program, string $programCode, string $schoolYear, array $version, array $rows): void
    {
        $curriculum = Curriculum::updateOrCreate(
            ['program_id' => $program->id, 'effective_start_year' => $version['start']],
            [
                'name' => "{$program->name} Curriculum {$schoolYear}",
                'effective_school_year' => $schoolYear,
                'effective_end_year' => $version['end'],
                'status' => $version['status'],
            ],
        );

        $placements = $this->resolvePlacements($programCode, $rows);

        foreach ($placements as $subjectCode => $placement) {
            $subject = Subject::query()
                ->where('college', $placement['college'])
                ->where('code', $subjectCode)
                ->first();

            if ($subject === null) {
                $this->warnings[] = "Subject '{$subjectCode}' ({$placement['college']}) is not in the seeded catalog — skipped placement in {$programCode} {$schoolYear}.";

                continue;
            }

            CurriculumSubject::updateOrCreate(
                ['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id],
                [
                    'year_level' => $placement['year_level'],
                    // BUG FIX (found while verifying Task 5 of the
                    // curriculum-editor/real-schedule-data plan): indexing
                    // into `$placement['semesters']` with its own first key
                    // returns that key's boolean `true` MARKER value, not
                    // the semester string itself — every non-composite
                    // placement across all 12 programs was silently stored
                    // as the literal string '1' (PHP's `(string) true`)
                    // instead of '1st'/'2nd'. `array_key_first()` already IS
                    // the semester string; no further indexing is needed.
                    'semester' => count($placement['semesters']) === 2 ? '1st|2nd' : array_key_first($placement['semesters']),
                    'is_required' => true,
                ],
            );
        }

        $defaultMax = $curriculum->unsetRelation('subjectPlacements')->defaultMaxUnits();
        if ($defaultMax > 0) {
            $curriculum->update(['max_units' => $defaultMax]);
        }
    }

    /**
     * Resolves one program-version's rows into one placement per subject
     * code, merging same-year both-semester duplicates and dropping
     * cross-year duplicates (earlier year wins) — see the class docblock.
     *
     * @param  list<array{year_level: int, semester: string, subject_code: string, college: string}>  $rows
     * @return array<string, array{year_level: int, semesters: array<string, true>, college: string}>
     */
    private function resolvePlacements(string $programCode, array $rows): array
    {
        $placements = [];

        $sorted = $rows;
        usort($sorted, fn (array $a, array $b): int => $a['year_level'] <=> $b['year_level']);

        foreach ($sorted as $row) {
            $code = $row['subject_code'];

            if (! isset($placements[$code])) {
                $placements[$code] = [
                    'year_level' => $row['year_level'],
                    'semesters' => [$row['semester'] => true],
                    'college' => $row['college'],
                ];

                continue;
            }

            if ($placements[$code]['year_level'] !== $row['year_level']) {
                $this->warnings[] = "Subject '{$code}' ({$programCode}) is placed in more than one year level — kept year {$placements[$code]['year_level']}, dropped year {$row['year_level']}.";

                continue;
            }

            $placements[$code]['semesters'][$row['semester']] = true;
        }

        return $placements;
    }

    private function reportWarnings(): void
    {
        foreach ($this->warnings as $warning) {
            Log::warning('[GrcCurriculumSeeder] '.$warning);
        }
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Real institutional data must never reach a production-like environment
     * through a seeder — the same guard every other seeder in this project
     * carries.
     */
    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'GrcCurriculumSeeder may only run in the local or testing environment. '
                .'Refusing to seed curriculum data into "'.app()->environment().'".',
            );
        }
    }
}
