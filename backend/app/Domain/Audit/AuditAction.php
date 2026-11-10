<?php

namespace App\Domain\Audit;

final class AuditAction
{
    public const CURRICULUM_CREATED = 'curriculum.created';

    public const CURRICULUM_UPDATED = 'curriculum.updated';

    public const SUBJECT_CREATED = 'subject.created';

    public const CURRICULUM_SUBMITTED = 'curriculum.submitted';

    public const CURRICULUM_DEAN_APPROVED = 'curriculum.dean_approved';

    public const CURRICULUM_DEAN_RETURNED = 'curriculum.dean_returned';

    public const CURRICULUM_EXECUTIVE_APPROVED = 'curriculum.executive_approved';

    public const CURRICULUM_EXECUTIVE_RETURNED = 'curriculum.executive_returned';

    public const FACULTY_AVAILABILITY_CREATED = 'faculty_availability.created';

    public const FACULTY_AVAILABILITY_UPDATED = 'faculty_availability.updated';

    public const FACULTY_AVAILABILITY_DELETED = 'faculty_availability.deleted';

    public const FACULTY_SUBJECT_PREFERENCE_CREATED = 'faculty_subject_preference.created';

    public const FACULTY_SUBJECT_PREFERENCE_UPDATED = 'faculty_subject_preference.updated';

    public const FACULTY_SUBJECT_PREFERENCE_DELETED = 'faculty_subject_preference.deleted';

    public const FACULTY_CURRICULUM_SUBJECT_PREFERENCE_CREATED = 'faculty_curriculum_subject_preference.created';

    public const FACULTY_CURRICULUM_SUBJECT_PREFERENCE_UPDATED = 'faculty_curriculum_subject_preference.updated';

    public const FACULTY_CURRICULUM_SUBJECT_PREFERENCE_DELETED = 'faculty_curriculum_subject_preference.deleted';

    public const FACULTY_SPECIALIZATION_CREATED = 'faculty_specialization.created';

    public const FACULTY_SPECIALIZATION_DELETED = 'faculty_specialization.deleted';

    public const FACULTY_SPECIALIZATION_UPDATED = 'faculty_specialization.updated';

    public const FACULTY_SPECIALIZATION_APPROVED = 'faculty_specialization.approved';

    public const FACULTY_SPECIALIZATION_REJECTED = 'faculty_specialization.rejected';

    public const SECTION_CREATED = 'section.created';

    public const SECTION_UPDATED = 'section.updated';

    public const SECTION_PROFESSOR_REASSIGNED = 'section.professor_reassigned';

    public const SECTION_CHANGE_REQUESTED = 'section_change_request.requested';

    public const SECTION_CHANGE_APPROVED = 'section_change_request.approved';

    public const SECTION_CHANGE_REJECTED = 'section_change_request.rejected';

    public const SECTION_CHANGE_CANCELLED = 'section_change_request.cancelled';

    public const FACULTY_LOAD_THRESHOLD_UPDATED = 'faculty_load_threshold.updated';

    public const FACULTY_LOAD_LIMIT_UPDATED = 'faculty_load_limit.updated';

    public const FACULTY_LOAD_OVERRIDE_SET = 'faculty_load_override.set';

    public const FACULTY_LOAD_OVERRIDE_CLEARED = 'faculty_load_override.cleared';

    public const SCHEDULE_PROPOSAL_CREATED = 'schedule_proposal.created';

    public const SCHEDULE_PROPOSAL_DEAN_APPROVED = 'schedule_proposal.dean_approved';

    public const SCHEDULE_PROPOSAL_DEAN_RETURNED = 'schedule_proposal.dean_returned';

    public const SCHEDULE_PROPOSAL_EXECUTIVE_APPROVED = 'schedule_proposal.executive_approved';

    public const SCHEDULE_PROPOSAL_EXECUTIVE_RETURNED = 'schedule_proposal.executive_returned';

    public const SCHEDULE_PROPOSAL_PUBLISHED = 'schedule_proposal.published';

    public const SECTION_PUBLISHED = 'section.published';

    public const SCHEDULE_PROPOSAL_CLOSED = 'schedule_proposal.closed';

    public const STUDENT_PROFILE_PROVISIONED = 'student_profile.provisioned';

    public const STUDENT_PROFILE_UPDATED = 'student_profile.updated';

    public const STUDENT_ACCOUNT_SETUP_INVITATION_SENT = 'student_account.setup_invitation_sent';

    public const STUDENT_ACCOUNT_SETUP_INVITATION_FAILED = 'student_account.setup_invitation_failed';

    public const STUDENT_ACCOUNT_ACTIVATED = 'student_account.activated';

    public const PRIVACY_NOTICE_ACCEPTED = 'privacy_notice.accepted';

    public const PRIVACY_NOTICE_ACKNOWLEDGED_FOR_STUDENT = 'privacy_notice.acknowledged_for_student';

    public const FACULTY_ACCOUNT_SETUP_INVITATION_SENT = 'faculty_account.setup_invitation_sent';

    public const FACULTY_ACCOUNT_SETUP_INVITATION_FAILED = 'faculty_account.setup_invitation_failed';

    public const FACULTY_ACCOUNT_ACTIVATED = 'faculty_account.activated';

    public const FACULTY_ACCOUNT_MERGED = 'faculty_account.merged';

    public const FACULTY_ACCOUNT_MERGE_ROLLED_BACK = 'faculty_account.merge_rolled_back';

    public const STAFF_ACCOUNT_SETUP_INVITATION_SENT = 'staff_account.setup_invitation_sent';

    public const STAFF_ACCOUNT_SETUP_INVITATION_FAILED = 'staff_account.setup_invitation_failed';

    public const STAFF_ACCOUNT_ACTIVATED = 'staff_account.activated';

    public const STUDENT_PROFILE_CHANGE_REQUESTED = 'student_profile_change.requested';

    public const STUDENT_PROFILE_CHANGE_REQUEST_UPDATED = 'student_profile_change.updated';

    public const STUDENT_PROFILE_CHANGE_REQUEST_CANCELLED = 'student_profile_change.cancelled';

    public const STUDENT_PROFILE_CHANGE_REQUEST_APPROVED = 'student_profile_change.approved';

    public const STUDENT_PROFILE_CHANGE_REQUEST_REJECTED = 'student_profile_change.rejected';

    public const STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED = 'student_profile.enrollment_category_reclassified';

    public const STUDENT_CURRICULUM_MIGRATED = 'student_profile.curriculum_migrated';

    public const AUDIT_LOG_LIST_VIEWED = 'audit_log.list_viewed';

    public const FACULTY_DIRECTORY_LIST_VIEWED = 'faculty_directory.list_viewed';

    public const ENROLLMENT_STATUS_STUDENT_LIST_VIEWED = 'enrollment_status_dashboard.student_list_viewed';

    public const ENROLLMENT_STATUS_STUDENT_VIEWED = 'enrollment_status_dashboard.student_viewed';

    public const FACULTY_WORKFORCE_PROFILE_UPDATED = 'faculty_workforce_profile.updated';

    public const ENROLLMENT_SUBMITTED = 'enrollment.submitted';

    public const ENROLLMENT_PROGRAM_HEAD_APPROVED = 'enrollment.program_head_approved';

    public const ENROLLMENT_PROGRAM_HEAD_REJECTED = 'enrollment.program_head_rejected';

    public const ENROLLMENT_REGISTRAR_APPROVED = 'enrollment.registrar_approved';

    public const ENROLLMENT_REGISTRAR_REJECTED = 'enrollment.registrar_rejected';

    public const ENROLLMENT_VOIDED = 'enrollment.voided';

    public const ENROLLMENT_CANCELLED_BY_STUDENT = 'enrollment.cancelled_by_student';

    public const ENROLLMENT_PROGRAM_HEAD_SUBJECTS_REVISED = 'enrollment.program_head_subjects_revised';

    public const ENROLLMENT_STUDENT_ACCEPTED_REVISION = 'enrollment.student_accepted_revision';

    public const ENROLLMENT_STUDENT_DECLINED_REVISION = 'enrollment.student_declined_revision';

    public const PROGRAM_SHIFT_RECORDED = 'program_shift.recorded';

    public const FEE_SCHEDULE_UPDATED = 'fee_schedule.updated';

    public const ENROLLMENT_SUBJECT_WAIVER_GRANTED = 'enrollment_subject_waiver.granted';

    public const ENROLLMENT_SUBJECT_WAIVER_REVOKED = 'enrollment_subject_waiver.revoked';

    public const ENROLLMENT_SUBJECT_WITHDRAWN = 'enrollment_subject.withdrawn';

    public const ADMISSION_REQUIREMENT_UPDATED = 'admission_requirement.updated';

    public const ADMISSION_REQUIREMENT_TYPE_CREATED = 'admission_requirement_type.created';

    public const ACADEMIC_GRADE_CREATED = 'academic_grade.created';

    public const ACADEMIC_GRADE_UPDATED = 'academic_grade.updated';

    public const ACADEMIC_GRADE_SUBMITTED = 'academic_grade.submitted';

    public const ACADEMIC_GRADE_LOCKED = 'academic_grade.locked';

    public const QUEUE_TICKET_SERVING_STARTED = 'queue_ticket.serving_started';

    public const QUEUE_TICKET_SERVED = 'queue_ticket.served';

    public const QUEUE_TICKET_SKIPPED = 'queue_ticket.skipped';

    public const QUEUE_TICKET_MARKED_PRIORITY = 'queue_ticket.marked_priority';

    public const ENROLLMENT_PAYMENT_CONFIRMED = 'enrollment.payment_confirmed';

    public const ASSESSMENT_ADJUSTED = 'assessment.adjusted';

    public const ASSESSMENT_SCHOLARSHIP_APPLIED = 'assessment.scholarship_applied';

    public const ASSESSMENT_SCHOLARSHIP_REMOVED = 'assessment.scholarship_removed';

    public const ASSESSMENT_FULL_PAYMENT_DISCOUNT_APPLIED = 'assessment.full_payment_discount_applied';

    public const PAYMENT_DISCOUNT_SETTING_UPDATED = 'payment_discount.updated';

    public const ACCOUNT_PAYMENT_RECORDED = 'account_payment.recorded';

    public const WITHDRAWAL_REQUEST_CREATED = 'withdrawal_request.created';

    public const WITHDRAWAL_REQUEST_APPROVED = 'withdrawal_request.approved';

    public const WITHDRAWAL_REQUEST_REJECTED = 'withdrawal_request.rejected';

    public const TRANSFEREE_CREDIT_CREATED = 'transferee_credit.created';

    public const TRANSFEREE_CREDIT_UPDATED = 'transferee_credit.updated';

    public const TRANSFEREE_CREDIT_ENDORSED = 'transferee_credit.endorsed';

    public const TRANSFEREE_CREDIT_APPROVED = 'transferee_credit.approved';

    public const TRANSFEREE_CREDIT_REJECTED = 'transferee_credit.rejected';

    public const TRANSFEREE_CREDIT_SUBMITTED = 'transferee_credit.submitted';

    public const TRANSFEREE_CREDIT_RETURNED = 'transferee_credit.returned';

    public const TRANSFEREE_CREDIT_DELETED = 'transferee_credit.deleted';

    /** A Transferee or Returnee said they have no subject to credit; the Program Chair reviews it. */
    public const NO_CREDIT_DECLARED = 'no_credit_declaration.declared';

    public const NO_CREDIT_CONFIRMED = 'no_credit_declaration.confirmed';

    public const TOR_DOCUMENT_UPLOADED = 'tor_document.uploaded';

    public const TOR_DOCUMENT_DELETED = 'tor_document.deleted';

    public const ACADEMIC_TERM_CREATED = 'academic_term.created';

    public const ACADEMIC_TERM_DRAFT_IDENTITY_UPDATED = 'academic_term.draft_identity_updated';

    public const SUBJECT_OFFERINGS_REPLACED = 'subject_offerings.replaced';

    public const SECTION_PLAN_SUBMITTED = 'section_plan.submitted';

    public const SECTION_PLAN_AUTO_ASSIGNED = 'section_plan.auto_assigned';

    public const ACADEMIC_TERM_WORKFLOW_CURRICULUM_STARTED = 'academic_term_workflow.curriculum_started';

    public const ACADEMIC_TERM_WORKFLOW_CURRICULUM_COMPLETED = 'academic_term_workflow.curriculum_completed';

    public const ACADEMIC_TERM_WORKFLOW_FACULTY_REVIEWED = 'academic_term_workflow.faculty_reviewed';

    public const ACADEMIC_TERM_CLOSED = 'academic_term.closed';

    public const ACADEMIC_TERM_ARCHIVED = 'academic_term.archived';

    public const ACADEMIC_TERM_ENROLLMENT_OPENED = 'academic_term.enrollment_opened';

    public const ACADEMIC_TERM_ENROLLMENT_SCHEDULE_UPDATED = 'academic_term.enrollment_schedule_updated';

    public const ENROLLMENT_CHANGE_REQUEST_CREATED = 'enrollment_change_request.created';

    public const ENROLLMENT_CHANGE_REQUEST_APPROVED = 'enrollment_change_request.approved';

    public const ENROLLMENT_CHANGE_REQUEST_REJECTED = 'enrollment_change_request.rejected';

    public const STUDENT_SCHEDULE_PREFERENCE_SAVED = 'student_schedule_preference.saved';

    public const QUEUE_TICKET_CLAIMED = 'queue_ticket.claimed';

    public const FRESHMAN_PAYMENT_CONFIRMED = 'student_profile.freshman_payment_confirmed';

    public const ENROLLMENT_FRESHMAN_AUTO_APPROVED = 'enrollment.freshman_auto_approved';

    public const FRESHMAN_QUEUE_LINK_EMAIL_SENT = 'queue_ticket.freshman_link_email_sent';

    public const FRESHMAN_QUEUE_LINK_EMAIL_SEND_FAILED = 'queue_ticket.freshman_link_email_send_failed';

    public const QUEUE_CYCLE_CUT_OFF = 'queue_cycle.cut_off';

    public const QUEUE_CYCLE_RESUMED = 'queue_cycle.resumed';

    public const QUEUE_CYCLE_CLOSED = 'queue_cycle.closed';

    public const QUEUE_KIOSK_CREDENTIAL_VIEWED = 'queue_kiosk_credential.viewed';

    public const QUEUE_KIOSK_PASSWORD_CHANGED = 'queue_kiosk.password_changed';

    public const LOGIN_SUCCEEDED = 'login.succeeded';

    public const LOGIN_FAILED = 'login.failed';

    public const PASSWORD_RESET_CODE_SENT = 'password_reset.code_sent';

    public const PASSWORD_RESET_CODE_SEND_FAILED = 'password_reset.code_send_failed';

    public const PASSWORD_RESET_COMPLETED = 'password_reset.completed';

    public const LOGIN_OTP_CHALLENGE_ISSUED = 'login_otp.challenge_issued';

    public const LOGIN_OTP_CHALLENGE_SEND_FAILED = 'login_otp.challenge_send_failed';

    public const LOGIN_OTP_FAILED = 'login_otp.failed';

    public const ENROLLMENT_CONFIRMATION_EMAIL_SENT = 'enrollment.confirmation_email_sent';

    public const ENROLLMENT_CONFIRMATION_EMAIL_SEND_FAILED = 'enrollment.confirmation_email_send_failed';

    public const SUPER_ADMIN_PROVISIONED = 'super_admin.provisioned';

    public const SUPER_ADMIN_DEACTIVATED = 'super_admin.deactivated';

    public const SUPER_ADMIN_ACTING_CONTEXT_CHANGED = 'super_admin.acting_context_changed';

    public const USER_ACCOUNT_ROLE_CHANGED = 'user_account.role_changed';

    public const USER_ACCOUNT_DEACTIVATED = 'user_account.deactivated';

    public const USER_ACCOUNT_REACTIVATED = 'user_account.reactivated';

    public const USER_ACCOUNT_SESSIONS_REVOKED = 'user_account.sessions_revoked';

    public const USER_ACCOUNT_PASSWORD_RESET_SENT = 'user_account.password_reset_sent';

    public const USER_ACCOUNT_DELETED = 'user_account.deleted';

    public const USER_ACCOUNT_UPDATED = 'user_account.updated';

    public const USER_ACCOUNT_PASSWORD_CHANGED = 'user_account.password_changed';

    public const USER_ACCOUNT_ARCHIVED = 'user_account.archived';

    public const USER_ACCOUNT_RESTORED = 'user_account.restored';

    public const USER_ACCOUNT_LIST_VIEWED = 'user_account.list_viewed';

    public const STUDENT_DEMOGRAPHICS_POPULATED = 'student_demographics.populated';

    public const STUDENT_DEMOGRAPHICS_WIPED = 'student_demographics.wiped';

    public const CHED_REPORT_VIEWED = 'ched_report.viewed';

    public const CHED_REPORT_EXPORTED = 'ched_report.exported';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::CURRICULUM_CREATED,
            self::CURRICULUM_UPDATED,
            self::SUBJECT_CREATED,
            self::CURRICULUM_SUBMITTED,
            self::CURRICULUM_DEAN_APPROVED,
            self::CURRICULUM_DEAN_RETURNED,
            self::CURRICULUM_EXECUTIVE_APPROVED,
            self::CURRICULUM_EXECUTIVE_RETURNED,
            self::FACULTY_AVAILABILITY_CREATED,
            self::FACULTY_AVAILABILITY_UPDATED,
            self::FACULTY_AVAILABILITY_DELETED,
            self::FACULTY_SUBJECT_PREFERENCE_CREATED,
            self::FACULTY_SUBJECT_PREFERENCE_UPDATED,
            self::FACULTY_SUBJECT_PREFERENCE_DELETED,
            self::FACULTY_CURRICULUM_SUBJECT_PREFERENCE_CREATED,
            self::FACULTY_CURRICULUM_SUBJECT_PREFERENCE_UPDATED,
            self::FACULTY_CURRICULUM_SUBJECT_PREFERENCE_DELETED,
            self::FACULTY_SPECIALIZATION_CREATED,
            self::FACULTY_SPECIALIZATION_DELETED,
            self::FACULTY_SPECIALIZATION_UPDATED,
            self::FACULTY_SPECIALIZATION_APPROVED,
            self::FACULTY_SPECIALIZATION_REJECTED,
            self::SECTION_CREATED,
            self::SECTION_UPDATED,
            self::SECTION_PROFESSOR_REASSIGNED,
            self::SECTION_CHANGE_REQUESTED,
            self::SECTION_CHANGE_APPROVED,
            self::SECTION_CHANGE_REJECTED,
            self::SECTION_CHANGE_CANCELLED,
            self::FACULTY_LOAD_THRESHOLD_UPDATED,
            self::FACULTY_LOAD_LIMIT_UPDATED,
            self::FACULTY_LOAD_OVERRIDE_SET,
            self::FACULTY_LOAD_OVERRIDE_CLEARED,
            self::SCHEDULE_PROPOSAL_CREATED,
            self::SCHEDULE_PROPOSAL_DEAN_APPROVED,
            self::SCHEDULE_PROPOSAL_DEAN_RETURNED,
            self::SCHEDULE_PROPOSAL_EXECUTIVE_APPROVED,
            self::SCHEDULE_PROPOSAL_EXECUTIVE_RETURNED,
            self::SCHEDULE_PROPOSAL_PUBLISHED,
            self::SECTION_PUBLISHED,
            self::SCHEDULE_PROPOSAL_CLOSED,
            self::STUDENT_PROFILE_PROVISIONED,
            self::STUDENT_PROFILE_UPDATED,
            self::STUDENT_ACCOUNT_SETUP_INVITATION_SENT,
            self::STUDENT_ACCOUNT_SETUP_INVITATION_FAILED,
            self::STUDENT_ACCOUNT_ACTIVATED,
            self::PRIVACY_NOTICE_ACCEPTED,
            self::PRIVACY_NOTICE_ACKNOWLEDGED_FOR_STUDENT,
            self::STUDENT_PROFILE_CHANGE_REQUESTED,
            self::STUDENT_PROFILE_CHANGE_REQUEST_UPDATED,
            self::STUDENT_PROFILE_CHANGE_REQUEST_CANCELLED,
            self::STUDENT_PROFILE_CHANGE_REQUEST_APPROVED,
            self::STUDENT_PROFILE_CHANGE_REQUEST_REJECTED,
            self::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED,
            self::STUDENT_CURRICULUM_MIGRATED,
            self::AUDIT_LOG_LIST_VIEWED,
            self::FACULTY_DIRECTORY_LIST_VIEWED,
            self::ENROLLMENT_STATUS_STUDENT_LIST_VIEWED,
            self::ENROLLMENT_STATUS_STUDENT_VIEWED,
            self::FACULTY_WORKFORCE_PROFILE_UPDATED,
            self::ENROLLMENT_SUBMITTED,
            self::ENROLLMENT_PROGRAM_HEAD_APPROVED,
            self::ENROLLMENT_PROGRAM_HEAD_REJECTED,
            self::ENROLLMENT_REGISTRAR_APPROVED,
            self::ENROLLMENT_REGISTRAR_REJECTED,
            self::ENROLLMENT_VOIDED,
            self::ENROLLMENT_CANCELLED_BY_STUDENT,
            self::ENROLLMENT_PROGRAM_HEAD_SUBJECTS_REVISED,
            self::ENROLLMENT_STUDENT_ACCEPTED_REVISION,
            self::ENROLLMENT_STUDENT_DECLINED_REVISION,
            self::PROGRAM_SHIFT_RECORDED,
            self::FEE_SCHEDULE_UPDATED,
            self::ENROLLMENT_SUBJECT_WAIVER_GRANTED,
            self::ENROLLMENT_SUBJECT_WAIVER_REVOKED,
            self::ENROLLMENT_SUBJECT_WITHDRAWN,
            self::ADMISSION_REQUIREMENT_UPDATED,
            self::ADMISSION_REQUIREMENT_TYPE_CREATED,
            self::ACADEMIC_GRADE_CREATED,
            self::ACADEMIC_GRADE_UPDATED,
            self::ACADEMIC_GRADE_SUBMITTED,
            self::ACADEMIC_GRADE_LOCKED,
            self::QUEUE_TICKET_SERVING_STARTED,
            self::QUEUE_TICKET_SERVED,
            self::QUEUE_TICKET_SKIPPED,
            self::QUEUE_TICKET_MARKED_PRIORITY,
            self::ENROLLMENT_PAYMENT_CONFIRMED,
            self::ASSESSMENT_ADJUSTED,
            self::ASSESSMENT_SCHOLARSHIP_APPLIED,
            self::ASSESSMENT_SCHOLARSHIP_REMOVED,
            self::ASSESSMENT_FULL_PAYMENT_DISCOUNT_APPLIED,
            self::PAYMENT_DISCOUNT_SETTING_UPDATED,
            self::ACCOUNT_PAYMENT_RECORDED,
            self::WITHDRAWAL_REQUEST_CREATED,
            self::WITHDRAWAL_REQUEST_APPROVED,
            self::WITHDRAWAL_REQUEST_REJECTED,
            self::TRANSFEREE_CREDIT_CREATED,
            self::TRANSFEREE_CREDIT_UPDATED,
            self::TRANSFEREE_CREDIT_ENDORSED,
            self::TRANSFEREE_CREDIT_APPROVED,
            self::TRANSFEREE_CREDIT_REJECTED,
            self::TRANSFEREE_CREDIT_SUBMITTED,
            self::TRANSFEREE_CREDIT_RETURNED,
            self::TRANSFEREE_CREDIT_DELETED,
            self::NO_CREDIT_DECLARED,
            self::NO_CREDIT_CONFIRMED,
            self::TOR_DOCUMENT_UPLOADED,
            self::TOR_DOCUMENT_DELETED,
            self::ACADEMIC_TERM_CREATED,
            self::ACADEMIC_TERM_DRAFT_IDENTITY_UPDATED,
            self::SUBJECT_OFFERINGS_REPLACED,
            self::SECTION_PLAN_SUBMITTED,
            self::SECTION_PLAN_AUTO_ASSIGNED,
            self::ACADEMIC_TERM_WORKFLOW_CURRICULUM_STARTED,
            self::ACADEMIC_TERM_WORKFLOW_CURRICULUM_COMPLETED,
            self::ACADEMIC_TERM_WORKFLOW_FACULTY_REVIEWED,
            self::ACADEMIC_TERM_CLOSED,
            self::ACADEMIC_TERM_ARCHIVED,
            self::ACADEMIC_TERM_ENROLLMENT_OPENED,
            self::ACADEMIC_TERM_ENROLLMENT_SCHEDULE_UPDATED,
            self::ENROLLMENT_CHANGE_REQUEST_CREATED,
            self::ENROLLMENT_CHANGE_REQUEST_APPROVED,
            self::ENROLLMENT_CHANGE_REQUEST_REJECTED,
            self::STUDENT_SCHEDULE_PREFERENCE_SAVED,
            self::QUEUE_TICKET_CLAIMED,
            self::FRESHMAN_PAYMENT_CONFIRMED,
            self::ENROLLMENT_FRESHMAN_AUTO_APPROVED,
            self::FRESHMAN_QUEUE_LINK_EMAIL_SENT,
            self::FRESHMAN_QUEUE_LINK_EMAIL_SEND_FAILED,
            self::QUEUE_CYCLE_CUT_OFF,
            self::QUEUE_CYCLE_RESUMED,
            self::QUEUE_CYCLE_CLOSED,
            self::QUEUE_KIOSK_CREDENTIAL_VIEWED,
            self::QUEUE_KIOSK_PASSWORD_CHANGED,
            self::LOGIN_SUCCEEDED,
            self::LOGIN_FAILED,
            self::PASSWORD_RESET_CODE_SENT,
            self::PASSWORD_RESET_CODE_SEND_FAILED,
            self::PASSWORD_RESET_COMPLETED,
            self::LOGIN_OTP_CHALLENGE_ISSUED,
            self::LOGIN_OTP_CHALLENGE_SEND_FAILED,
            self::LOGIN_OTP_FAILED,
            self::FACULTY_ACCOUNT_SETUP_INVITATION_SENT,
            self::FACULTY_ACCOUNT_SETUP_INVITATION_FAILED,
            self::FACULTY_ACCOUNT_ACTIVATED,
            self::FACULTY_ACCOUNT_MERGED,
            self::FACULTY_ACCOUNT_MERGE_ROLLED_BACK,
            self::STAFF_ACCOUNT_SETUP_INVITATION_SENT,
            self::STAFF_ACCOUNT_SETUP_INVITATION_FAILED,
            self::STAFF_ACCOUNT_ACTIVATED,
            self::ENROLLMENT_CONFIRMATION_EMAIL_SENT,
            self::ENROLLMENT_CONFIRMATION_EMAIL_SEND_FAILED,
            self::SUPER_ADMIN_PROVISIONED,
            self::SUPER_ADMIN_DEACTIVATED,
            self::SUPER_ADMIN_ACTING_CONTEXT_CHANGED,
            self::USER_ACCOUNT_ROLE_CHANGED,
            self::USER_ACCOUNT_DEACTIVATED,
            self::USER_ACCOUNT_REACTIVATED,
            self::USER_ACCOUNT_SESSIONS_REVOKED,
            self::USER_ACCOUNT_PASSWORD_RESET_SENT,
            self::USER_ACCOUNT_DELETED,
            self::USER_ACCOUNT_UPDATED,
            self::USER_ACCOUNT_PASSWORD_CHANGED,
            self::USER_ACCOUNT_ARCHIVED,
            self::USER_ACCOUNT_RESTORED,
            self::USER_ACCOUNT_LIST_VIEWED,
            self::STUDENT_DEMOGRAPHICS_POPULATED,
            self::STUDENT_DEMOGRAPHICS_WIPED,
            self::CHED_REPORT_VIEWED,
            self::CHED_REPORT_EXPORTED,
        ];
    }
}
