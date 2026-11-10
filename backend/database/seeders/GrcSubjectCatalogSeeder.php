<?php

namespace Database\Seeders;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

/**
 * Seeds the real four-college subject catalog (CCS, COE, COA, CBAE — the
 * only colleges currently supported) from
 * `database/seeders/data/organizations-subjects-prerequisites.csv`, a
 * 409-row fixture reverse-engineered from real GRC block-section schedules.
 *
 * Parses the CSV at runtime instead of hand-transcribing it into a PHP
 * array: the source has 409 rows and genuine embedded-comma quoted fields
 * (e.g. `"Science, Technology and Society"`) that a naive
 * `explode(',', ...)` would corrupt.
 *
 * This seeder owns only the subject catalog itself — `code`, `college`,
 * `title`, `units`. Curriculum placements and prerequisites are
 * `GrcCurriculumSeeder` and `GrcPrerequisiteSeeder`'s jobs respectively; both
 * depend on this seeder having run first.
 */
final class GrcSubjectCatalogSeeder extends Seeder
{
    private const CSV_PATH = __DIR__.'/data/organizations-subjects-prerequisites.csv';

    /**
     * `$csvPathOverride` exists only so tests can point this seeder at a
     * small fixture CSV, without depending on the real 409-row catalog.
     * Laravel's container resolves this with no arguments during normal
     * seeding, so the default keeps that path unaffected.
     */
    public function __construct(private readonly ?string $csvPathOverride = null) {}

    public function run(): void
    {
        $this->guardEnvironment();

        $rows = $this->readCsv($this->csvPathOverride ?? self::CSV_PATH);

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->seedSubject($row);
            }
        });
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException(
                "GrcSubjectCatalogSeeder could not find its CSV fixture at {$path}",
            );
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

                // The source file carries a UTF-8 BOM, which otherwise
                // prepends invisible bytes to the first column name
                // ("organization"), silently breaking every `$row['organization']`
                // lookup below.
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
     * @param  array<string, string>  $row
     */
    private function seedSubject(array $row): void
    {
        $college = CollegeCode::tryFrom(strtolower(trim($row['organization'] ?? '')));

        if ($college === null) {
            return;
        }

        $title = trim($row['description']);

        Subject::updateOrCreate(
            ['college' => $college, 'code' => trim($row['subject_code'])],
            [
                'title' => $title,
                'units' => (float) $row['units'],
                'status' => SubjectStatus::Active,
                // Without this, GenerateFacultyAssignmentRecommendations's
                // room auto-assignment refuses every subject outright — see
                // PredictivePlanningInputSeeder for the same "LAB" heuristic
                // this mirrors, scoped there to a precondition the real
                // dataset's term timeline can never satisfy.
                'room_requirement' => str_contains(strtoupper($title), 'LAB') ? 'laboratory' : 'lecture',
            ],
        );
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
                'GrcSubjectCatalogSeeder may only run in the local or testing environment. '
                .'Refusing to seed subject catalog data into "'.app()->environment().'".',
            );
        }
    }
}
