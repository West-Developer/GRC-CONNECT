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
use App\Models\SubjectPrerequisite;
use Database\Seeders\GrcPrerequisiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class GrcPrerequisiteSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mirrors `AllOrganizationsSubjectsPrerequisitesSeederTest`'s fixture:
     * a clean chain (TEST2 -> TEST1), a self-referencing row (TEST3), and
     * an unresolvable reference (TEST4 -> NOPE).
     */
    private const FIXTURE_CSV = <<<'CSV'
        organization,subject_code,subject_code_key,description,description_variants,units,offered_semesters,source_academic_years,prerequisite_codes,prerequisite_logic,prerequisite_confidence,possible_prerequisite_codes,possible_prerequisite_logic,professor_surnames,sections,prerequisite_notes
        CCS,TEST1,TEST1,Test Subject One,Test Subject One,3,1st Semester,AY 2026-2027,NONE,NONE,NONE_IDENTIFIED,NONE,NONE,SURNAME,SEC 101,No prerequisite.
        CCS,TEST2,TEST2,Test Subject Two,Test Subject Two,3,1st Semester,AY 2026-2027,TEST1,ALL,INFERRED_SEQUENCE,NONE,NONE,SURNAME,SEC 101,Follows Test Subject One.
        CCS,TEST3,TEST3,Test Subject Three,Test Subject Three,3,1st Semester,AY 2026-2027,TEST3,ALL,INFERRED_SEQUENCE,NONE,NONE,SURNAME,SEC 101,Self-referencing test row.
        CCS,TEST4,TEST4,Test Subject Four,Test Subject Four,3,1st Semester,AY 2026-2027,NOPE,ALL,INFERRED_SEQUENCE,NONE,NONE,SURNAME,SEC 101,Unresolvable prerequisite test row.

        CSV;

    /**
     * @return list<Curriculum> two versions of one program, each with the
     *                          fixture's four test subjects placed
     */
    private function makeTwoCurriculumVersions(): array
    {
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);

        $subjects = [];
        foreach (['TEST1', 'TEST2', 'TEST3', 'TEST4'] as $code) {
            $subjects[$code] = Subject::create([
                'code' => $code, 'college' => CollegeCode::Ccs,
                'title' => "{$code} Title", 'units' => 3, 'status' => SubjectStatus::Active,
            ]);
        }

        $curricula = [];
        foreach ([['2024-2029', 2024, 2029, CurriculumStatus::Active], ['2018-2023', 2018, 2023, CurriculumStatus::Archived]] as [$label, $start, $end, $status]) {
            $curriculum = Curriculum::create([
                'program_id' => $program->id, 'name' => "BSIT Curriculum {$label}",
                'effective_school_year' => $label, 'effective_start_year' => $start,
                'effective_end_year' => $end, 'status' => $status,
            ]);

            foreach ($subjects as $subject) {
                CurriculumSubject::create([
                    'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
                    'year_level' => 1, 'semester' => '1st', 'is_required' => true,
                ]);
            }

            $curricula[] = $curriculum;
        }

        return $curricula;
    }

    private function writeFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grc_prereq_test_').'.csv';
        file_put_contents($path, self::FIXTURE_CSV);

        return $path;
    }

    private function seederForFixture(string $path): GrcPrerequisiteSeeder
    {
        return new GrcPrerequisiteSeeder($path);
    }

    public function test_a_prerequisite_is_anchored_to_every_curriculum_version_where_the_subject_is_placed(): void
    {
        [$newest, $oldest] = $this->makeTwoCurriculumVersions();
        $fixturePath = $this->writeFixture();

        try {
            $this->seederForFixture($fixturePath)->run();

            $test1 = Subject::where('code', 'TEST1')->sole();
            $test2 = Subject::where('code', 'TEST2')->sole();

            foreach ([$newest, $oldest] as $curriculum) {
                $placement = CurriculumSubject::where('curriculum_id', $curriculum->id)->where('subject_id', $test2->id)->sole();
                $this->assertDatabaseHas('subject_prerequisites', [
                    'curriculum_subject_id' => $placement->id,
                    'prerequisite_subject_id' => $test1->id,
                ]);
            }

            $this->assertSame(2, SubjectPrerequisite::where('prerequisite_subject_id', $test1->id)->count());
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_self_referencing_prerequisite_is_skipped_with_a_warning(): void
    {
        $this->makeTwoCurriculumVersions();
        $fixturePath = $this->writeFixture();

        try {
            $seeder = $this->seederForFixture($fixturePath);
            $seeder->run();

            $test3 = Subject::where('code', 'TEST3')->sole();
            $this->assertSame(0, SubjectPrerequisite::where('prerequisite_subject_id', $test3->id)->count());
            $this->assertTrue(
                collect($seeder->warnings())->contains(fn (string $warning): bool => str_contains($warning, 'Self-referencing prerequisite ignored')),
                'Expected a self-referencing-prerequisite warning.',
            );
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_unresolved_prerequisite_is_skipped_with_a_warning_not_an_exception(): void
    {
        $this->makeTwoCurriculumVersions();
        $fixturePath = $this->writeFixture();

        try {
            $seeder = $this->seederForFixture($fixturePath);
            $seeder->run();

            // Only TEST2 -> TEST1 resolves, anchored to both curriculum versions.
            $this->assertDatabaseCount('subject_prerequisites', 2);
            $this->assertTrue(
                collect($seeder->warnings())->contains(fn (string $warning): bool => str_contains($warning, 'Unresolved prerequisite') && str_contains($warning, 'NOPE')),
                'Expected an unresolved-prerequisite warning mentioning NOPE.',
            );
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_reseeding_updates_in_place_without_duplicates(): void
    {
        $this->makeTwoCurriculumVersions();
        $fixturePath = $this->writeFixture();

        try {
            $seeder = $this->seederForFixture($fixturePath);
            $seeder->run();
            $ids = SubjectPrerequisite::orderBy('id')->pluck('id')->all();

            $seeder->run();

            $this->assertSame($ids, SubjectPrerequisite::orderBy('id')->pluck('id')->all());
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_seeder_refuses_to_run_outside_local_and_testing(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        app(GrcPrerequisiteSeeder::class)->run();
    }
}
