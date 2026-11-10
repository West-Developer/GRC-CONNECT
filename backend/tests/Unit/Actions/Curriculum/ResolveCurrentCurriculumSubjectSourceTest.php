<?php

namespace Tests\Unit\Actions\Curriculum;

use App\Actions\Curriculum\ResolveCurrentCurriculumSubjectSource;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ResolveCurrentCurriculumSubjectSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_the_active_curriculum_matching_the_current_term_and_loads_its_subjects(): void
    {
        $program = $this->program();
        $old = $this->curriculum($program, '2025-2026', 'Old active curriculum');
        $current = $this->curriculum($program, '2026-2027', 'Current active curriculum');
        $newerMismatch = $this->curriculum($program, '2027-2028', 'Newer active curriculum');
        $oldSubject = $this->subject('CS-OLD');
        $currentSubject = $this->subject('CS-CURRENT');
        $newerSubject = $this->subject('CS-NEWER');
        $this->place($old, $oldSubject);
        $this->place($current, $currentSubject);
        $this->place($newerMismatch, $newerSubject);
        $this->setCurrentTerm('2026-2027');

        $source = app(ResolveCurrentCurriculumSubjectSource::class)->execute($program);

        self::assertNotNull($source);
        self::assertSame($current->id, $source->id);
        self::assertTrue($source->relationLoaded('subjectPlacements'));
        self::assertTrue($source->subjectPlacements->firstOrFail()->relationLoaded('subject'));
        self::assertSame([$currentSubject->id], $source->subjectPlacements->pluck('subject_id')->all());
    }

    public function test_it_falls_back_to_the_latest_active_curriculum_in_existing_list_order(): void
    {
        $program = $this->program();
        $older = $this->curriculum($program, '2025-2026', 'Z older active curriculum');
        $latestByName = $this->curriculum($program, '2027-2028', 'A latest active curriculum');
        $this->curriculum($program, '2027-2028', 'Z latest active curriculum');
        $this->curriculum($program, '2028-2029', 'Draft curriculum', CurriculumStatus::Draft);
        $this->setCurrentTerm('2026-2027');

        $source = app(ResolveCurrentCurriculumSubjectSource::class)->execute($program);

        self::assertNotNull($source);
        self::assertSame($latestByName->id, $source->id);
        self::assertNotSame($older->id, $source->id);
    }

    public function test_it_returns_null_when_the_program_has_no_active_curriculum(): void
    {
        $program = $this->program();
        $this->curriculum($program, '2026-2027', 'Draft curriculum', CurriculumStatus::Draft);
        $this->setCurrentTerm('2026-2027');

        self::assertNull(app(ResolveCurrentCurriculumSubjectSource::class)->execute($program));
    }

    private function program(): Program
    {
        return Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);
    }

    private function curriculum(Program $program, string $schoolYear, string $name, CurriculumStatus $status = CurriculumStatus::Active): Curriculum
    {
        return Curriculum::create([
            'program_id' => $program->id,
            'name' => $name,
            'effective_school_year' => $schoolYear,
            'status' => $status,
        ]);
    }

    private function subject(string $code): Subject
    {
        return Subject::create([
            'code' => $code,
            'college' => CollegeCode::Ccs,
            'title' => $code.' title',
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);
    }

    private function place(Curriculum $curriculum, Subject $subject): void
    {
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => '1st',
            'is_required' => true,
        ]);
    }

    private function setCurrentTerm(string $schoolYear): void
    {
        $term = AcademicTerm::create([
            'school_year' => $schoolYear,
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        DB::table('academic_term_current_slots')->where('id', 1)->update(['academic_term_id' => $term->id]);
    }
}
