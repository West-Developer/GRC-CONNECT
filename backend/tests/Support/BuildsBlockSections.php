<?php

namespace Tests\Support;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;

/**
 * Published block sections for a freshman's program and year: the ready-made world
 * a freshman is auto-enrolled into. One subject per block keeps the numbers
 * easy to follow; `capacity` / `enrolled_count` control whether a block is full.
 */
trait BuildsBlockSections
{
    protected function ongoingTerm(bool $windowOpen = true): AcademicTerm
    {
        return AcademicTerm::query()->first() ?? AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
            'enrollment_opens_at' => $windowOpen ? now()->subDay() : now()->subMonth(),
            'enrollment_closes_at' => $windowOpen ? now()->addWeek() : now()->subWeek(),
        ]);
    }

    protected function yearOnePlan(AcademicTerm $term, Curriculum $curriculum): AcademicTermSectionPlan
    {
        return AcademicTermSectionPlan::query()->where('academic_term_id', $term->id)->where('year_level', 1)->first()
            ?? AcademicTermSectionPlan::create([
                'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
                'college' => 'ccs', 'year_level' => 1, 'section_count' => 3,
                'students_per_block' => 40, 'status' => 'submitted',
            ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function blockSection(
        AcademicTerm $term,
        AcademicTermSectionPlan $plan,
        string $blockCode,
        string $subjectCode,
        array $overrides = [],
    ): Section {
        $subject = Subject::create(['code' => $subjectCode, 'title' => $subjectCode.' Title', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create([
            'curriculum_id' => $plan->curriculum_id, 'subject_id' => $subject->id,
            'year_level' => $plan->year_level, 'semester' => '1st', 'is_required' => true,
        ]);
        $professor = User::create([
            'name' => 'Prof '.$subjectCode, 'email' => strtolower($subjectCode).'.prof@grc.test',
            'password' => 'correct-horse-battery-staple', 'role' => UserRole::Faculty,
            'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return Section::create(array_merge([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $subject->id,
            'section_code' => $blockCode,
            'professor_id' => $professor->id,
            'schedule_days' => 'MWF',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
            'room' => 'LAB-1',
            'capacity' => 40,
            'is_block_exclusive' => true,
            'status' => SectionStatus::Published,
        ], $overrides));
    }

    protected function fullBlock(AcademicTerm $term, AcademicTermSectionPlan $plan, string $blockCode, string $subjectCode): Section
    {
        return $this->blockSection($term, $plan, $blockCode, $subjectCode, ['capacity' => 1, 'enrolled_count' => 1]);
    }
}
