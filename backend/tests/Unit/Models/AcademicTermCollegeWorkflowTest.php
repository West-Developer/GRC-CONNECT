<?php

namespace Tests\Unit\Models;

use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Models\AcademicTermCollegeWorkflow;
use Tests\TestCase;

final class AcademicTermCollegeWorkflowTest extends TestCase
{
    public function test_stage_attribute_uses_the_canonical_enum_cast(): void
    {
        $workflow = new AcademicTermCollegeWorkflow;
        $workflow->forceFill([
            'academic_term_id' => 1,
            'college' => 'ccs',
            'stage' => AcademicTermCollegeWorkflowStage::FacultyInput,
        ]);

        self::assertSame(AcademicTermCollegeWorkflowStage::FacultyInput, $workflow->stage);
    }

    public function test_stage_helpers_identify_editable_and_completed_states(): void
    {
        $editable = new AcademicTermCollegeWorkflow;
        $editable->forceFill(['stage' => AcademicTermCollegeWorkflowStage::CurriculumPreparation]);
        self::assertTrue($editable->isCurriculumEditable());
        self::assertFalse($editable->hasSubmittedSchedule());

        $completed = new AcademicTermCollegeWorkflow;
        $completed->forceFill(['stage' => AcademicTermCollegeWorkflowStage::ForDeanApproval]);
        self::assertFalse($completed->isCurriculumEditable());
        self::assertTrue($completed->hasSubmittedSchedule());
    }
}
