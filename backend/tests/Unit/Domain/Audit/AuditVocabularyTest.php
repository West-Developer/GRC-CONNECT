<?php

namespace Tests\Unit\Domain\Audit;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use PHPUnit\Framework\TestCase;

final class AuditVocabularyTest extends TestCase
{
    public function test_action_values_include_academic_term_workflow_transitions(): void
    {
        $expected = [
                'curriculum.created',
                'curriculum.updated',
                'subject.created',
                'curriculum.submitted',
                'curriculum.dean_approved',
                'curriculum.dean_returned',
                'curriculum.executive_approved',
                'curriculum.executive_returned',
                'faculty_availability.created',
                'faculty_availability.updated',
                'faculty_availability.deleted',
                'faculty_subject_preference.created',
                'faculty_subject_preference.updated',
                'faculty_subject_preference.deleted',
                'faculty_curriculum_subject_preference.created',
                'faculty_curriculum_subject_preference.updated',
                'faculty_curriculum_subject_preference.deleted',
                'faculty_specialization.created',
                'faculty_specialization.deleted',
                'section.created',
                'section.updated',
                'faculty_load_threshold.updated',
                'schedule_proposal.created',
                'schedule_proposal.dean_approved',
                'schedule_proposal.dean_returned',
                'schedule_proposal.executive_approved',
                'schedule_proposal.executive_returned',
                'schedule_proposal.published',
                'section.published',
                'schedule_proposal.closed',
                'student_profile.provisioned',
                'student_profile.enrollment_category_reclassified',
                'student_profile.curriculum_migrated',
                'audit_log.list_viewed',
                'faculty_directory.list_viewed',
                'faculty_workforce_profile.updated',
                'enrollment.submitted',
                'enrollment.program_head_approved',
                'enrollment.program_head_rejected',
                'enrollment.registrar_approved',
                'enrollment.registrar_rejected',
                'enrollment.voided',
                'academic_grade.created',
                'academic_grade.updated',
                'academic_grade.submitted',
                'academic_grade.locked',
                'queue_ticket.serving_started',
                'queue_ticket.served',
                'queue_ticket.skipped',
                'queue_ticket.marked_priority',
                'enrollment.payment_confirmed',
                'assessment.adjusted',
                'account_payment.recorded',
                'withdrawal_request.created',
                'withdrawal_request.approved',
                'withdrawal_request.rejected',
                'transferee_credit.created',
                'transferee_credit.updated',
                'transferee_credit.approved',
                'transferee_credit.rejected',
                'academic_term.created',
                'academic_term.draft_identity_updated',
                'subject_offerings.replaced',
                'section_plan.submitted',
                'section_plan.auto_assigned',
                'academic_term_workflow.curriculum_started',
                'academic_term_workflow.curriculum_completed',
                'academic_term_workflow.faculty_reviewed',
                'academic_term.closed',
                'academic_term.archived',
                'academic_term.enrollment_opened',
                'academic_term.enrollment_schedule_updated',
                'enrollment_change_request.created',
                'enrollment_change_request.approved',
                'enrollment_change_request.rejected',
                'student_schedule_preference.saved',
                'queue_ticket.claimed',
                'queue_cycle.cut_off',
                'queue_cycle.resumed',
                'queue_cycle.closed',
                'queue_kiosk_credential.viewed',
                'queue_kiosk.password_changed',
                'transferee_credit.submitted',
                'transferee_credit.returned',
                'transferee_credit.deleted',
            ];
        $actual = AuditAction::values();

        // Approved values must never disappear or be renamed; new slices may add more.
        self::assertSame([], array_values(array_diff($expected, $actual)));
        self::assertSame(array_values(array_unique($actual)), $actual);
    }

    public function test_auditable_type_values_are_the_approved_subjects(): void
    {
        $expected = [
                'curriculum',
                'subject',
                'faculty_availability',
                'faculty_subject_preference',
                'faculty_curriculum_subject_preference',
                'faculty_specialization',
                'section',
                'faculty_load_threshold',
                'schedule_proposal',
                'student_profile',
                'audit_log',
                'faculty_directory',
                'faculty_workforce_profile',
                'enrollment',
                'assessment',
                'account_payment',
                'academic_grade',
                'queue_ticket',
                'withdrawal_request',
                'transferee_credit',
                'academic_term',
                'subject_offering',
                'academic_term_workflow',
                'section_plan',
                'academic_term_year_level_window',
                'enrollment_change_request',
                'student_schedule_preference',
                'queue_cycle',
                'queue_kiosk_credential',
            ];
        $actual = AuditableType::values();

        // Approved values must never disappear or be renamed; new slices may add more.
        self::assertSame([], array_values(array_diff($expected, $actual)));
        self::assertSame(array_values(array_unique($actual)), $actual);
    }
}
