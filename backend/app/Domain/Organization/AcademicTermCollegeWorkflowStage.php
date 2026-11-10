<?php

namespace App\Domain\Organization;

enum AcademicTermCollegeWorkflowStage: string
{
    case Draft = 'draft';
    case CurriculumPreparation = 'curriculum_preparation';
    case FacultyInput = 'faculty_input';
    case SchedulePreparation = 'schedule_preparation';
    case ForDeanApproval = 'for_dean_approval';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::CurriculumPreparation => 'Curriculum Preparation',
            self::FacultyInput => 'Faculty Input',
            self::SchedulePreparation => 'Schedule Preparation',
            self::ForDeanApproval => 'For Dean Approval',
        };
    }
}
