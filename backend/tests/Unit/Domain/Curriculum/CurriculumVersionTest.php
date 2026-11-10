<?php

namespace Tests\Unit\Domain\Curriculum;

use App\Domain\Curriculum\CurriculumVersion;
use App\Models\Curriculum;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class CurriculumVersionTest extends TestCase
{
    private function curriculum(string $name, ?int $start, ?int $end): Curriculum
    {
        $curriculum = new Curriculum;
        $curriculum->name = $name;
        $curriculum->effective_start_year = $start;
        $curriculum->effective_end_year = $end;

        return $curriculum;
    }

    /** @return Collection<int, Curriculum> */
    private function threeRealVersions(): Collection
    {
        return new Collection([
            $this->curriculum('2012-2017', 2012, 2017),
            $this->curriculum('2018-2023', 2018, 2023),
            $this->curriculum('2024-2029', 2024, 2029),
        ]);
    }

    public function test_an_entry_year_inside_the_middle_range_resolves_to_it(): void
    {
        $resolved = CurriculumVersion::resolveForEntryYear($this->threeRealVersions(), 2023);

        self::assertSame('2018-2023', $resolved?->name);
    }

    public function test_an_entry_year_inside_the_newest_range_resolves_to_it(): void
    {
        $resolved = CurriculumVersion::resolveForEntryYear($this->threeRealVersions(), 2024);

        self::assertSame('2024-2029', $resolved?->name);
    }

    public function test_the_first_year_of_a_range_resolves_to_that_range_not_the_previous_one(): void
    {
        $resolved = CurriculumVersion::resolveForEntryYear($this->threeRealVersions(), 2018);

        self::assertSame('2018-2023', $resolved?->name);
    }

    public function test_the_last_year_of_a_range_still_resolves_to_that_range(): void
    {
        $resolved = CurriculumVersion::resolveForEntryYear($this->threeRealVersions(), 2017);

        self::assertSame('2012-2017', $resolved?->name);
    }

    public function test_an_entry_year_past_every_range_falls_back_to_the_latest_version(): void
    {
        $resolved = CurriculumVersion::resolveForEntryYear($this->threeRealVersions(), 2031);

        self::assertSame('2024-2029', $resolved?->name);
    }

    public function test_an_entry_year_before_every_range_resolves_to_nothing(): void
    {
        $resolved = CurriculumVersion::resolveForEntryYear($this->threeRealVersions(), 2005);

        self::assertNull($resolved);
    }

    public function test_curricula_with_no_year_range_set_are_ignored(): void
    {
        $versions = new Collection([
            $this->curriculum('Still drafting', null, null),
            $this->curriculum('2024-2029', 2024, 2029),
        ]);

        $resolved = CurriculumVersion::resolveForEntryYear($versions, 2026);

        self::assertSame('2024-2029', $resolved?->name);
    }

    public function test_an_empty_collection_resolves_to_nothing(): void
    {
        self::assertNull(CurriculumVersion::resolveForEntryYear(new Collection, 2024));
    }
}
