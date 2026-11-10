<?php

namespace App\Domain\Notifications;

enum NotificationType: string
{
    case SchedulePublished = 'schedule_published';
    case EnrollmentSubmitted = 'enrollment_submitted';
    case EnrollmentRegistrarApproved = 'enrollment_registrar_approved';
    case EnrollmentRegistrarRejected = 'enrollment_registrar_rejected';
    case EnrollmentVoided = 'enrollment_voided';
    case EnrollmentProgramHeadApproved = 'enrollment_program_head_approved';
    case EnrollmentProgramHeadRejected = 'enrollment_program_head_rejected';
    case EnrollmentProgramHeadSubjectsRevised = 'enrollment_program_head_subjects_revised';
    case EnrollmentRevisionAccepted = 'enrollment_revision_accepted';
    case EnrollmentRevisionDeclined = 'enrollment_revision_declined';
    case AcademicGradeLocked = 'academic_grade_locked';
    case EnrollmentPaymentConfirmed = 'enrollment_payment_confirmed';
    case WithdrawalRequestSubmitted = 'withdrawal_request_submitted';
    case WithdrawalRequestApproved = 'withdrawal_request_approved';
    case WithdrawalRequestRejected = 'withdrawal_request_rejected';
    /** The Registrar Head withdrew one or more of the student's subjects (shown as W on the Prospectus). */
    case SubjectWithdrawn = 'subject_withdrawn';
    case TransfereeCreditApproved = 'transferee_credit_approved';
    case TransfereeCreditRejected = 'transferee_credit_rejected';
    case TransfereeCreditRequested = 'transferee_credit_requested';
    case TransfereeCreditEndorsed = 'transferee_credit_endorsed';
    case TransfereeCreditReturned = 'transferee_credit_returned';
    /** A Transferee or Returnee has no subject to credit (to the Program Chair), and the Chair's confirmation (to the student). */
    case TransfereeNoCreditDeclared = 'transferee_no_credit_declared';
    case TransfereeNoCreditConfirmed = 'transferee_no_credit_confirmed';
    /** A transferee's credit mapping has nothing left open, so they now hold a queue number (ADR 0043, amended). */
    case TransfereeQueueNumberIssued = 'transferee_queue_number_issued';
    case ScheduleSubmittedForDean = 'schedule_submitted_for_dean';
    case ScheduleDeanApproved = 'schedule_dean_approved';
    case ScheduleExecutiveApproved = 'schedule_executive_approved';
    case ScheduleReturned = 'schedule_returned';
    case EnrollmentCategoryReclassified = 'enrollment_category_reclassified';
    case EnrollmentChangeRequestSubmitted = 'enrollment_change_request_submitted';
    case EnrollmentChangeRequestApproved = 'enrollment_change_request_approved';
    case EnrollmentChangeRequestRejected = 'enrollment_change_request_rejected';
    case CurriculumSubmittedForDean = 'curriculum_submitted_for_dean';
    case CurriculumDeanApproved = 'curriculum_dean_approved';
    case CurriculumExecutiveApproved = 'curriculum_executive_approved';
    case CurriculumReturned = 'curriculum_returned';
    case QueueTicketClaimed = 'queue_ticket_claimed';
    case QueueCycleCutOff = 'queue_cycle_cut_off';
    case StudentProfileChangeApproved = 'student_profile_change_approved';
    case StudentProfileChangeRejected = 'student_profile_change_rejected';
    case FacultySpecializationApproved = 'faculty_specialization_approved';
    case FacultySpecializationRejected = 'faculty_specialization_rejected';
    case SectionAssigned = 'section_assigned';
    case SectionChangeRequested = 'section_change_requested';
    case SectionChangeApproved = 'section_change_approved';
    case SectionChangeRejected = 'section_change_rejected';
    case SectionProfessorReassigned = 'section_professor_reassigned';
    case StudentYearLevelPromoted = 'student_year_level_promoted';
    // ADR 0043: a freshman who paid while every section was full, and the moment one opens.
    case FreshmanAwaitingSection = 'freshman_awaiting_section';
    case FreshmanSectionAvailable = 'freshman_section_available';
    // A freshman who paid and is submitted to a block, waiting for the Registrar's approval (2026-10-04).
    case FreshmanAwaitingRegistrarApproval = 'freshman_awaiting_registrar_approval';
}
