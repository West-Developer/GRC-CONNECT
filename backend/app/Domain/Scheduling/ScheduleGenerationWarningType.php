<?php

namespace App\Domain\Scheduling;

enum ScheduleGenerationWarningType: string
{
    case RoomMetadataIncomplete = 'room_metadata_incomplete';
    case RoomRequirementMissing = 'room_requirement_missing';
    case ScheduleMetadataIncomplete = 'schedule_metadata_incomplete';
    case NoRoomAvailable = 'no_room_available';
    /** A room already on the section double-books another booking, and no free replacement exists. */
    case RoomConflictUnresolved = 'room_conflict_unresolved';
    case NoDraftSections = 'no_draft_sections';
    case FacultyUnavailable = 'faculty_unavailable';
    case SectionPlanSubmittedSkip = 'section_plan_submitted_skip';
    case ManualSectionCountKept = 'manual_section_count_kept';
    case NoForecastReturned = 'no_forecast_returned';
    case NoCurriculumSubjects = 'no_curriculum_subjects';
    case InsufficientHistory = 'insufficient_history';
    case PredictionServiceUnavailable = 'prediction_service_unavailable';
}
