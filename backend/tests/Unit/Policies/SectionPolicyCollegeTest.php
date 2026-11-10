<?php

namespace Tests\Unit\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\SectionPlanStatus;
use App\Models\AcademicTermSectionPlan;
use App\Models\Section;
use App\Models\User;
use App\Policies\SectionPolicy;
use PHPUnit\Framework\TestCase;

final class SectionPolicyCollegeTest extends TestCase
{
    public function test_program_chair_can_update_a_draft_section_plan_for_their_college(): void
    {
        $chair = new User([
            'role' => UserRole::ProgramChair,
            'college' => CollegeCode::Ccs,
        ]);
        $plan = new AcademicTermSectionPlan([
            'college' => CollegeCode::Ccs->value,
            'status' => SectionPlanStatus::Draft,
        ]);
        $section = new Section;
        $section->section_plan_id = 1;
        $section->setRelation('sectionPlan', $plan);

        self::assertTrue((new SectionPolicy)->update($chair, $section));
    }

    public function test_program_chair_cannot_update_another_colleges_section_plan(): void
    {
        $chair = new User([
            'role' => UserRole::ProgramChair,
            'college' => CollegeCode::Ccs,
        ]);
        $plan = new AcademicTermSectionPlan([
            'college' => CollegeCode::Coe->value,
            'status' => SectionPlanStatus::Draft,
        ]);
        $section = new Section;
        $section->section_plan_id = 1;
        $section->setRelation('sectionPlan', $plan);

        self::assertFalse((new SectionPolicy)->update($chair, $section));
    }
}
