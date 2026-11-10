<?php

namespace Tests\Feature\Database;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Subject;
use Database\Seeders\GrcCurriculumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class GrcCurriculumSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A small fixture exercising every quirk the real 683-row placement map
     * contains: a plain single placement (ITC), a subject genuinely offered
     * in both semesters of the same year (ECOMMTEST, year 2), and a subject
     * placed in two different year levels (STRAMANTEST, years 2 and 3 —
     * year 2 must win). `IAS2` is deliberately included because the real
     * `ARCHIVED_VARIATIONS` constant removes it from BSIT's archived
     * versions, so this fixture also exercises that path unmodified.
     */
    private const FIXTURE_CSV = <<<'CSV'
        college,program_code,year_level,semester,subject_code
        ccs,BSIT,1,1st,ITC
        ccs,BSIT,3,1st,IAS2
        ccs,BSIT,2,1st,ECOMMTEST
        ccs,BSIT,2,2nd,ECOMMTEST
        ccs,BSIT,2,1st,STRAMANTEST
        ccs,BSIT,3,1st,STRAMANTEST

        CSV;

    private function seedProgramAndSubjects(): Program
    {
        $program = Program::create([
            'code' => 'BSIT', 'name' => 'BS Information Technology',
            'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs,
        ]);

        foreach (['ITC', 'IAS2', 'ECOMMTEST', 'STRAMANTEST'] as $code) {
            Subject::create([
                'code' => $code, 'college' => CollegeCode::Ccs,
                'title' => "{$code} Title", 'units' => 3, 'status' => SubjectStatus::Active,
            ]);
        }

        return $program;
    }

    private function writeFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grc_curriculum_test_').'.csv';
        file_put_contents($path, self::FIXTURE_CSV);

        return $path;
    }

    private function seederForFixture(string $path): GrcCurriculumSeeder
    {
        return new GrcCurriculumSeeder($path);
    }

    public function test_seeder_creates_three_versions_with_correct_year_ranges_and_status(): void
    {
        $this->seedProgramAndSubjects();
        $fixturePath = $this->writeFixture();

        try {
            $this->seederForFixture($fixturePath)->run();

            $versions = Curriculum::orderBy('effective_start_year')->get();
            $this->assertCount(3, $versions);

            [$oldest, $middle, $newest] = $versions;
            $this->assertSame(2012, $oldest->effective_start_year);
            $this->assertSame(2017, $oldest->effective_end_year);
            $this->assertSame(CurriculumStatus::Archived, $oldest->status);

            $this->assertSame(2018, $middle->effective_start_year);
            $this->assertSame(2023, $middle->effective_end_year);
            $this->assertSame(CurriculumStatus::Archived, $middle->status);

            $this->assertSame(2024, $newest->effective_start_year);
            $this->assertSame(2029, $newest->effective_end_year);
            $this->assertSame(CurriculumStatus::Active, $newest->status);
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_a_subject_offered_both_semesters_of_the_same_year_gets_a_composite_semester(): void
    {
        $this->seedProgramAndSubjects();
        $fixturePath = $this->writeFixture();

        try {
            $this->seederForFixture($fixturePath)->run();

            $newest = Curriculum::where('effective_start_year', 2024)->sole();
            $subject = Subject::where('code', 'ECOMMTEST')->sole();
            $placement = CurriculumSubject::where('curriculum_id', $newest->id)->where('subject_id', $subject->id)->sole();

            $this->assertSame(2, $placement->year_level);
            $this->assertSame('1st|2nd', $placement->semester);
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_a_subject_placed_in_two_year_levels_keeps_only_the_earlier_one(): void
    {
        $this->seedProgramAndSubjects();
        $fixturePath = $this->writeFixture();

        try {
            $seeder = $this->seederForFixture($fixturePath);
            $seeder->run();

            $newest = Curriculum::where('effective_start_year', 2024)->sole();
            $subject = Subject::where('code', 'STRAMANTEST')->sole();
            $placement = CurriculumSubject::where('curriculum_id', $newest->id)->where('subject_id', $subject->id)->sole();

            $this->assertSame(2, $placement->year_level);
            $this->assertTrue(
                collect($seeder->warnings())->contains(
                    fn (string $warning): bool => str_contains($warning, 'STRAMANTEST') && str_contains($warning, 'more than one year level'),
                ),
                'Expected a cross-year duplicate warning for STRAMANTEST.',
            );
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_reseeding_updates_in_place_without_duplicates(): void
    {
        $this->seedProgramAndSubjects();
        $fixturePath = $this->writeFixture();

        try {
            $seeder = $this->seederForFixture($fixturePath);
            $seeder->run();
            $curriculumIds = Curriculum::orderBy('id')->pluck('id')->all();
            $placementIds = CurriculumSubject::orderBy('id')->pluck('id')->all();

            $seeder->run();

            $this->assertSame(3, Curriculum::count());
            $this->assertSame($curriculumIds, Curriculum::orderBy('id')->pluck('id')->all());
            $this->assertSame($placementIds, CurriculumSubject::orderBy('id')->pluck('id')->all());
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_seeder_refuses_to_run_outside_local_and_testing(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        app(GrcCurriculumSeeder::class)->run();
    }
}
