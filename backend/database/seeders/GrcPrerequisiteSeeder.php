<?php

namespace Database\Seeders;

use App\Domain\Organization\CollegeCode;
use App\Models\CurriculumSubject;
use App\Models\Subject;
use App\Models\SubjectPrerequisite;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SplFileObject;

/**
 * Seeds the 74 confirmed prerequisites from
 * `database/seeders/data/organizations-subjects-prerequisites.csv`'s
 * `prerequisite_codes`/`prerequisite_logic` columns — the only prerequisite
 * data the source schedules actually contain (the source's own CSV has no
 * prerequisite column at all; this data is a separate, already-vetted
 * analysis). `possible_prerequisite_codes`/`possible_prerequisite_logic` are
 * that analysis's own lower-confidence guesses and are deliberately NOT
 * seeded here — they need human/Program-Chair confirmation first, added
 * through the (not-yet-built) Curriculum Editor.
 *
 * A prerequisite is anchored to a `curriculum_subject_id`, not a bare
 * `subject_id` (ADR 0009), so this seeder applies to EVERY curriculum
 * version where the subject is actually placed — active and archived alike
 * — rather than just the current 2024-2029 one. A subject an archived
 * version's `ARCHIVED_VARIATIONS` diff removed (see `GrcCurriculumSeeder`)
 * simply has no placement there, so it is naturally skipped, not an error.
 *
 * `prerequisite_logic` ALL/ANY is simplified to a flat AND-list, matching
 * `AllOrganizationsSubjectsPrerequisitesSeeder`'s prior behavior: the
 * existing `subject_prerequisites` schema has no OR-grouping concept.
 * Pipe-delimited candidates (alternate code spellings for the same real
 * prerequisite, e.g. `SYSARC1|SYSARCH1`) each get linked if they resolve
 * against that college's catalog.
 *
 * Depends on `ProgramSeeder`, `GrcSubjectCatalogSeeder`, and
 * `GrcCurriculumSeeder` having run first.
 */
final class GrcPrerequisiteSeeder extends Seeder
{
    private const CSV_PATH = __DIR__.'/data/organizations-subjects-prerequisites.csv';

    private const PLACEHOLDER_MINIMUM_GRADE = '3.00';

    private const NONE_MARKER = 'NONE';

    /** @var array<string, array<string, Subject>> college value => subject_code_key => Subject */
    private array $subjectIndex = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * `$csvPathOverride` exists only so tests can point this seeder at a
     * small fixture CSV. Laravel's container resolves this with no
     * arguments during normal seeding.
     */
    public function __construct(private readonly ?string $csvPathOverride = null) {}

    public function run(): void
    {
        $this->guardEnvironment();

        $rows = $this->readCsv($this->csvPathOverride ?? self::CSV_PATH);
        $this->buildSubjectIndex($rows);

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->seedPrerequisites($row);
            }
        });

        $this->reportWarnings();
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("GrcPrerequisiteSeeder could not find its CSV fixture at {$path}");
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $header = null;
        $rows = [];

        foreach ($file as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }

            if ($header === null) {
                $header = array_map(static fn (?string $value): string => trim((string) $value), $line);

                if (isset($header[0]) && str_starts_with($header[0], "\xEF\xBB\xBF")) {
                    $header[0] = substr($header[0], 3);
                }

                continue;
            }

            if (count($line) !== count($header)) {
                continue;
            }

            /** @var array<string, string> $row */
            $row = array_combine($header, $line);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function buildSubjectIndex(array $rows): void
    {
        foreach ($rows as $row) {
            $college = CollegeCode::tryFrom(strtolower(trim($row['organization'] ?? '')));

            if ($college === null) {
                continue;
            }

            $subject = Subject::query()
                ->where('college', $college)
                ->where('code', trim($row['subject_code']))
                ->first();

            if ($subject === null) {
                continue;
            }

            $key = strtoupper(trim($row['subject_code_key']));
            $this->subjectIndex[$college->value][$key] = $subject;
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function seedPrerequisites(array $row): void
    {
        $college = CollegeCode::tryFrom(strtolower(trim($row['organization'] ?? '')));

        if ($college === null) {
            return;
        }

        $prerequisiteCodes = trim($row['prerequisite_codes'] ?? self::NONE_MARKER);

        if ($prerequisiteCodes === '' || strtoupper($prerequisiteCodes) === self::NONE_MARKER) {
            return;
        }

        $ownKey = strtoupper(trim($row['subject_code_key']));
        $ownSubject = $this->subjectIndex[$college->value][$ownKey] ?? null;

        if ($ownSubject === null) {
            $this->warnings[] = "Prerequisite skipped: subject '{$row['subject_code']}' ({$college->value}) is not in the seeded catalog.";

            return;
        }

        $placements = CurriculumSubject::query()->where('subject_id', $ownSubject->id)->get();

        if ($placements->isEmpty()) {
            $this->warnings[] = "Prerequisite skipped: subject '{$row['subject_code']}' ({$college->value}) has no curriculum placement.";

            return;
        }

        $candidates = array_map('trim', explode('|', $prerequisiteCodes));
        $resolvedAny = false;

        foreach ($candidates as $candidate) {
            $candidateKey = strtoupper($candidate);

            if ($candidateKey === $ownKey) {
                $this->warnings[] = "Self-referencing prerequisite ignored: '{$row['subject_code']}' ({$college->value}) lists itself as its own prerequisite.";

                continue;
            }

            $prerequisiteSubject = $this->subjectIndex[$college->value][$candidateKey] ?? null;

            if ($prerequisiteSubject === null) {
                $this->warnings[] = "Unresolved prerequisite: '{$row['subject_code']}' ({$college->value}) references unknown code '{$candidate}'.";

                continue;
            }

            foreach ($placements as $placement) {
                SubjectPrerequisite::updateOrCreate(
                    [
                        'curriculum_subject_id' => $placement->id,
                        'prerequisite_subject_id' => $prerequisiteSubject->id,
                    ],
                    ['minimum_grade' => self::PLACEHOLDER_MINIMUM_GRADE],
                );
            }

            $resolvedAny = true;
        }

        if (! $resolvedAny) {
            $this->warnings[] = "No prerequisite candidate resolved for '{$row['subject_code']}' ({$college->value}) out of [{$prerequisiteCodes}].";
        }
    }

    private function reportWarnings(): void
    {
        foreach ($this->warnings as $warning) {
            Log::warning('[GrcPrerequisiteSeeder] '.$warning);
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
                'GrcPrerequisiteSeeder may only run in the local or testing environment. '
                .'Refusing to seed prerequisite data into "'.app()->environment().'".',
            );
        }
    }
}
