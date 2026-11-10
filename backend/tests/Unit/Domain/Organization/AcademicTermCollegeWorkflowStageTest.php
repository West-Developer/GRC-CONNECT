<?php

namespace Tests\Unit\Domain\Organization;

use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use PHPUnit\Framework\TestCase;

final class AcademicTermCollegeWorkflowStageTest extends TestCase
{
    public function test_workflow_stage_values_are_independent_from_term_statuses(): void
    {
        self::assertSame(
            ['draft', 'curriculum_preparation', 'faculty_input', 'schedule_preparation', 'for_dean_approval'],
            array_column(AcademicTermCollegeWorkflowStage::cases(), 'value'),
        );
    }

    public function test_workflow_stage_labels_are_human_readable(): void
    {
        self::assertSame('Curriculum Preparation', AcademicTermCollegeWorkflowStage::CurriculumPreparation->label());
        self::assertSame('Faculty Input', AcademicTermCollegeWorkflowStage::FacultyInput->label());
        self::assertSame('Schedule Preparation', AcademicTermCollegeWorkflowStage::SchedulePreparation->label());
    }
}
