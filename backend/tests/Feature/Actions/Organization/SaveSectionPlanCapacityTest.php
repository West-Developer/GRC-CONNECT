<?php

namespace Tests\Feature\Actions\Organization;

use App\Actions\Organization\SaveSectionPlan;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CapacitySource;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Organization\SectionPlanStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A Program Chair can allot extra seats to one specific subject within a
 * year level (e.g. for irregular students who all still need that one
 * course) without changing every other block's capacity. `SubjectOffering.
 * max_section_capacity` already existed for this; `SaveSectionPlan::release()`
 * previously computed it into a local object and then discarded it, falling
 * back to the year level's `students_per_block` for every subject
 * unconditionally.
 */
final class SaveSectionPlanCapacityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create([
            'code' => 'BSIT', 'name' => 'BS Information Technology',
            'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs,
        ]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeChair(): User
    {
        return User::create([
            'name' => 'Chair', 'email' => 'chair.capacity@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::ProgramChair,
            'college' => CollegeCode::Ccs, 'status' => UserStatus::Active,
        ]);
    }

    private function placeSubject(Curriculum $curriculum, string $code): Subject
    {
        $subject = Subject::create(['code' => $code, 'title' => "{$code} Title", 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
        ]);

        return $subject;
    }

    public function test_a_subject_with_a_configured_offering_seats_its_own_capacity(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $chair = $this->makeChair();

        $configuredSubject = $this->placeSubject($curriculum, 'ITC');
        $plainSubject = $this->placeSubject($curriculum, 'ITCL');

        SubjectOffering::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'subject_id' => $configuredSubject->id, 'year_level' => 1, 'semester' => '1st',
            'min_section_capacity' => 10, 'max_section_capacity' => 45, 'recommended_sections' => 1,
        ]);

        AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => 1, 'section_count' => 1,
            'students_per_block' => 40, 'status' => SectionPlanStatus::Draft,
        ]);

        app(SaveSectionPlan::class)->release(
            $term, $curriculum->id, $chair, new AuditRequestContext('capacity-test', null), 1,
        );

        $configuredSection = Section::query()->where('subject_id', $configuredSubject->id)->sole();
        $plainSection = Section::query()->where('subject_id', $plainSubject->id)->sole();

        self::assertSame(45, $configuredSection->capacity);
        self::assertSame(CapacitySource::Plan, $configuredSection->capacity_source);
        self::assertSame(40, $plainSection->capacity);
        self::assertSame(CapacitySource::Plan, $plainSection->capacity_source);
    }

    public function test_a_manually_overridden_section_keeps_its_capacity_across_a_later_release(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $chair = $this->makeChair();

        $subject = $this->placeSubject($curriculum, 'ITC');

        SubjectOffering::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st',
            'min_section_capacity' => 10, 'max_section_capacity' => 45, 'recommended_sections' => 1,
        ]);

        AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => 1, 'section_count' => 1,
            'students_per_block' => 40, 'status' => SectionPlanStatus::Draft,
        ]);

        $action = app(SaveSectionPlan::class);
        $context = new AuditRequestContext('capacity-test', null);
        $action->release($term, $curriculum->id, $chair, $context, 1);

        $section = Section::query()->where('subject_id', $subject->id)->sole();
        $section->update(['capacity' => 55, 'capacity_source' => CapacitySource::Manual]);

        $action->release($term, $curriculum->id, $chair, $context, 1);

        self::assertSame(55, $section->refresh()->capacity);
        self::assertSame(CapacitySource::Manual, $section->capacity_source);
    }

    /**
     * Per product direction, a Program Chair must still be able to submit
     * a curriculum for Dean/Executive Director approval even when it
     * currently has fewer than 4 year-level plans — e.g. a program with no
     * continuing students in its upper years yet, or one still missing
     * historical demand data for a specific year. Only a curriculum with
     * zero plans at all (nothing to release) is rejected.
     */
    public function test_releasing_every_year_succeeds_with_fewer_than_four_year_level_plans(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $chair = $this->makeChair();
        $subject = $this->placeSubject($curriculum, 'ITC');

        AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => 1, 'section_count' => 1,
            'students_per_block' => 40, 'status' => SectionPlanStatus::Draft,
        ]);
        AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => 2, 'section_count' => 1,
            'students_per_block' => 40, 'status' => SectionPlanStatus::Draft,
        ]);

        $plans = app(SaveSectionPlan::class)->release(
            $term, $curriculum->id, $chair, new AuditRequestContext('partial-release-test', null),
        );

        self::assertCount(2, $plans);
        self::assertDatabaseHas('sections', [
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
        ]);
    }

    public function test_releasing_a_curriculum_with_zero_plans_is_still_rejected(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $chair = $this->makeChair();

        try {
            app(SaveSectionPlan::class)->release(
                $term, $curriculum->id, $chair, new AuditRequestContext('empty-release-test', null),
            );
            self::fail('Expected releasing a curriculum with no section plans at all to be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('counts', $exception->errors());
        }
    }
}
