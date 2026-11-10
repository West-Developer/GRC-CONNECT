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
use Database\Seeders\GrcCurriculumScheduleReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class GrcCurriculumScheduleReferenceSeederTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_CSV = <<<'CSV'
        college,program_code,year_level,semester,subject_code,day,start_time,end_time,room,modality,professor_name,sched_id,notes
        ccs,BSIT,1,1st,ITC,THIRS,07:30,09:30,ONLINE,ONLINE,MR. MACINAS,39633,
        ccs,BSIT,1,1st,MATHWRLD,Fri,07:30,10:30,3A,HYFLEX A,,39883,

        CSV;

    private function seedCurriculumAndSubjects(): Curriculum
    {
        $program = Program::create([
            'code' => 'BSIT', 'name' => 'BS Information Technology',
            'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum 2024-2029',
            'effective_school_year' => '2024-2029', 'effective_start_year' => 2024,
            'effective_end_year' => 2029, 'status' => CurriculumStatus::Active,
        ]);

        foreach (['ITC', 'MATHWRLD'] as $code) {
            $subject = Subject::create([
                'code' => $code, 'college' => CollegeCode::Ccs,
                'title' => "{$code} Title", 'units' => 3, 'status' => SubjectStatus::Active,
            ]);
            CurriculumSubject::create([
                'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
                'year_level' => 1, 'semester' => '1st', 'is_required' => true,
            ]);
        }

        return $curriculum;
    }

    private function writeFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grc_schedule_ref_test_').'.csv';
        file_put_contents($path, self::FIXTURE_CSV);

        return $path;
    }

    private function seederForFixture(string $path): GrcCurriculumScheduleReferenceSeeder
    {
        return new GrcCurriculumScheduleReferenceSeeder($path);
    }

    public function test_it_fills_the_reference_columns_for_a_matching_placement(): void
    {
        $curriculum = $this->seedCurriculumAndSubjects();
        $fixturePath = $this->writeFixture();

        try {
            $this->seederForFixture($fixturePath)->run();

            $itc = Subject::where('code', 'ITC')->sole();
            $placement = CurriculumSubject::where('curriculum_id', $curriculum->id)->where('subject_id', $itc->id)->sole();

            $this->assertSame('THU', $placement->reference_day);
            $this->assertSame('07:30:00', $placement->reference_start_time);
            $this->assertSame('09:30:00', $placement->reference_end_time);
            $this->assertSame('ONLINE', $placement->reference_room);
            $this->assertSame('ONLINE', $placement->reference_modality);
            $this->assertSame('MR. MACINAS', $placement->reference_professor_name);
            $this->assertSame('39633', $placement->reference_sched_id);
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_a_blank_professor_name_in_the_source_stays_null(): void
    {
        $curriculum = $this->seedCurriculumAndSubjects();
        $fixturePath = $this->writeFixture();

        try {
            $this->seederForFixture($fixturePath)->run();

            $mathwrld = Subject::where('code', 'MATHWRLD')->sole();
            $placement = CurriculumSubject::where('curriculum_id', $curriculum->id)->where('subject_id', $mathwrld->id)->sole();

            $this->assertNull($placement->reference_professor_name);
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_reseeding_updates_in_place_without_duplicates(): void
    {
        $this->seedCurriculumAndSubjects();
        $fixturePath = $this->writeFixture();

        try {
            $seeder = $this->seederForFixture($fixturePath);
            $seeder->run();
            $ids = CurriculumSubject::orderBy('id')->pluck('id')->all();

            $seeder->run();

            $this->assertSame($ids, CurriculumSubject::orderBy('id')->pluck('id')->all());
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_seeder_refuses_to_run_outside_local_and_testing(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        app(GrcCurriculumScheduleReferenceSeeder::class)->run();
    }

    /**
     * Most source cells omit AM/PM, so "12:00" -> "3:00" read literally as
     * 12:00 -> 03:00 — an interval whose end precedes its start. Because
     * every conflict rule compares HH:MM:SS as strings, such a row matched
     * nothing and let whole groups of sections share one room, time and
     * professor.
     */
    public function test_a_meridiem_less_afternoon_range_resolves_forwards(): void
    {
        $csv = <<<'CSV'
            college,program_code,year_level,semester,subject_code,day,start_time,end_time,room,modality,professor_name,sched_id,notes
            ccs,BSIT,1,1st,ITC,MON,12:00,3:00,2A,,,1,
            ccs,BSIT,1,1st,MATHWRLD,MON,10:30,1:30,2A,,,2,

            CSV;
        $curriculum = $this->seedCurriculumAndSubjects();
        $path = tempnam(sys_get_temp_dir(), 'grc_schedule_ref_pm_').'.csv';
        file_put_contents($path, $csv);

        try {
            $this->seederForFixture($path)->run();

            $itc = CurriculumSubject::where('curriculum_id', $curriculum->id)
                ->where('subject_id', Subject::where('code', 'ITC')->sole()->id)->sole();
            $this->assertSame('12:00:00', $itc->reference_start_time);
            $this->assertSame('15:00:00', $itc->reference_end_time);

            $math = CurriculumSubject::where('curriculum_id', $curriculum->id)
                ->where('subject_id', Subject::where('code', 'MATHWRLD')->sole()->id)->sole();
            $this->assertSame('10:30:00', $math->reference_start_time);
            $this->assertSame('13:30:00', $math->reference_end_time);
        } finally {
            unlink($path);
        }
    }

    /**
     * An evening class written "6:00" -> "9:00" is 18:00-21:00: GRC's
     * teaching day starts at 07:30, so a meridiem-less start before 07:00
     * is afternoon, and the end follows it.
     */
    public function test_a_meridiem_less_evening_range_resolves_to_the_evening(): void
    {
        $csv = <<<'CSV'
            college,program_code,year_level,semester,subject_code,day,start_time,end_time,room,modality,professor_name,sched_id,notes
            ccs,BSIT,1,1st,ITC,MON,6:00,9:00,2A,,,1,

            CSV;
        $curriculum = $this->seedCurriculumAndSubjects();
        $path = tempnam(sys_get_temp_dir(), 'grc_schedule_ref_eve_').'.csv';
        file_put_contents($path, $csv);

        try {
            $this->seederForFixture($path)->run();

            $itc = CurriculumSubject::where('curriculum_id', $curriculum->id)
                ->where('subject_id', Subject::where('code', 'ITC')->sole()->id)->sole();
            $this->assertSame('18:00:00', $itc->reference_start_time);
            $this->assertSame('21:00:00', $itc->reference_end_time);
        } finally {
            unlink($path);
        }
    }

    /** An explicit AM/PM marker is trusted exactly as written, never shifted. */
    public function test_an_explicit_morning_range_is_never_shifted(): void
    {
        $csv = <<<'CSV'
            college,program_code,year_level,semester,subject_code,day,start_time,end_time,room,modality,professor_name,sched_id,notes
            ccs,BSIT,1,1st,ITC,MON,7:30AM,9:30AM,2A,,,1,

            CSV;
        $curriculum = $this->seedCurriculumAndSubjects();
        $path = tempnam(sys_get_temp_dir(), 'grc_schedule_ref_am_').'.csv';
        file_put_contents($path, $csv);

        try {
            $this->seederForFixture($path)->run();

            $itc = CurriculumSubject::where('curriculum_id', $curriculum->id)
                ->where('subject_id', Subject::where('code', 'ITC')->sole()->id)->sole();
            $this->assertSame('07:30:00', $itc->reference_start_time);
            $this->assertSame('09:30:00', $itc->reference_end_time);
        } finally {
            unlink($path);
        }
    }
}
