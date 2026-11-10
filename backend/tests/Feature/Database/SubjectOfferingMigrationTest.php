<?php

namespace Tests\Feature\Database;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use App\Models\SubjectOffering;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SubjectOfferingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_subject_offerings_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('subject_offerings'));
        $this->assertTrue(Schema::hasColumns('subject_offerings', [
            'id', 'academic_term_id', 'curriculum_id', 'subject_id', 'year_level',
            'semester', 'min_section_capacity', 'max_section_capacity',
            'recommended_sections', 'created_at', 'updated_at',
        ]));
    }

    public function test_the_same_subject_cannot_be_offered_twice_for_one_term_and_curriculum(): void
    {
        [$term, $curriculum, $subject] = $this->makeScaffold();

        SubjectOffering::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => '1st',
            'min_section_capacity' => 20,
            'max_section_capacity' => 40,
            'recommended_sections' => 2,
        ]);

        $this->expectException(QueryException::class);

        SubjectOffering::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => '1st',
            'min_section_capacity' => 10,
            'max_section_capacity' => 30,
            'recommended_sections' => 1,
        ]);
    }

    public function test_deleting_the_academic_term_cascades_to_its_offerings(): void
    {
        [$term, $curriculum, $subject] = $this->makeScaffold();
        $offering = SubjectOffering::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => '1st',
            'min_section_capacity' => 20,
            'max_section_capacity' => 40,
            'recommended_sections' => 2,
        ]);

        $term->delete();

        $this->assertDatabaseMissing('subject_offerings', ['id' => $offering->id]);
    }

    public function test_deleting_the_curriculum_cascades_to_its_offerings(): void
    {
        [$term, $curriculum, $subject] = $this->makeScaffold();
        $offering = SubjectOffering::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => '1st',
            'min_section_capacity' => 20,
            'max_section_capacity' => 40,
            'recommended_sections' => 2,
        ]);

        $curriculum->delete();

        $this->assertDatabaseMissing('subject_offerings', ['id' => $offering->id]);
    }

    public function test_a_subject_referenced_by_an_offering_cannot_be_deleted(): void
    {
        [$term, $curriculum, $subject] = $this->makeScaffold();
        SubjectOffering::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => '1st',
            'min_section_capacity' => 20,
            'max_section_capacity' => 40,
            'recommended_sections' => 2,
        ]);

        $this->expectException(QueryException::class);

        $subject->delete();
    }

    /**
     * @return array{0: AcademicTerm, 1: Curriculum, 2: Subject}
     */
    private function makeScaffold(): array
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::Draft,
        ]);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS 2026', 'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Active,
        ]);
        $subject = Subject::create(['code' => 'SO101', 'title' => 'Subject Offering Test', 'units' => 3, 'status' => SubjectStatus::Active]);

        return [$term, $curriculum, $subject];
    }
}
