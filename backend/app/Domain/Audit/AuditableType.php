<?php

namespace App\Domain\Audit;

final class AuditableType
{
    public const CURRICULUM = 'curriculum';

    public const SUBJECT = 'subject';

    public const FACULTY_AVAILABILITY = 'faculty_availability';

    public const FACULTY_SUBJECT_PREFERENCE = 'faculty_subject_preference';

    public const FACULTY_CURRICULUM_SUBJECT_PREFERENCE = 'faculty_curriculum_subject_preference';

    public const FACULTY_SPECIALIZATION = 'faculty_specialization';

    public const SECTION = 'section';

    public const FACULTY_LOAD_THRESHOLD = 'faculty_load_threshold';

    public const SCHEDULE_PROPOSAL = 'schedule_proposal';

    public const STUDENT_PROFILE = 'student_profile';

    public const FACULTY_ACCOUNT = 'faculty_account';

    public const STAFF_ACCOUNT = 'staff_account';

    public const STUDENT_PROFILE_CHANGE_REQUEST = 'student_profile_change_request';

    public const AUDIT_LOG = 'audit_log';

    public const FACULTY_DIRECTORY = 'faculty_directory';

    public const FACULTY_WORKFORCE_PROFILE = 'faculty_workforce_profile';

    public const ENROLLMENT = 'enrollment';

    public const ASSESSMENT = 'assessment';

    public const ACCOUNT_PAYMENT = 'account_payment';

    public const ACADEMIC_GRADE = 'academic_grade';

    public const QUEUE_TICKET = 'queue_ticket';

    public const WITHDRAWAL_REQUEST = 'withdrawal_request';

    public const TRANSFEREE_CREDIT = 'transferee_credit';

    public const TOR_DOCUMENT = 'tor_document';

    public const ACADEMIC_TERM = 'academic_term';

    public const SUBJECT_OFFERING = 'subject_offering';

    public const ACADEMIC_TERM_WORKFLOW = 'academic_term_workflow';

    public const SECTION_PLAN = 'section_plan';

    public const ACADEMIC_TERM_YEAR_LEVEL_WINDOW = 'academic_term_year_level_window';

    public const ENROLLMENT_CHANGE_REQUEST = 'enrollment_change_request';

    public const STUDENT_SCHEDULE_PREFERENCE = 'student_schedule_preference';

    public const QUEUE_CYCLE = 'queue_cycle';

    public const QUEUE_KIOSK_CREDENTIAL = 'queue_kiosk_credential';

    public const ENROLLMENT_STATUS_DASHBOARD = 'enrollment_status_dashboard';

    public const ENROLLMENT_SUBJECT_WAIVER = 'enrollment_subject_waiver';

    public const STUDENT_ADMISSION_REQUIREMENT = 'student_admission_requirement';

    public const ADMISSION_REQUIREMENT_TYPE = 'admission_requirement_type';

    public const SECTION_CHANGE_REQUEST = 'section_change_request';

    public const PROGRAM_SHIFT = 'program_shift';

    public const FEE_SCHEDULE = 'fee_schedule';

    public const PAYMENT_DISCOUNT_SETTING = 'payment_discount_setting';

    public const FACULTY_LOAD_LIMIT = 'faculty_load_limit';

    public const FACULTY_LOAD_OVERRIDE = 'faculty_load_override';

    /** Role-agnostic — login applies to every role, unlike the role-specific *_ACCOUNT constants above. */
    public const USER_ACCOUNT = 'user_account';

    public const STUDENT_DEMOGRAPHICS = 'student_demographics';

    public const CHED_REPORT = 'ched_report';

    public const PRIVACY_NOTICE_ACCEPTANCE = 'privacy_notice_acceptance';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::CURRICULUM,
            self::SUBJECT,
            self::FACULTY_AVAILABILITY,
            self::FACULTY_SUBJECT_PREFERENCE,
            self::FACULTY_CURRICULUM_SUBJECT_PREFERENCE,
            self::FACULTY_SPECIALIZATION,
            self::SECTION,
            self::FACULTY_LOAD_THRESHOLD,
            self::SCHEDULE_PROPOSAL,
            self::STUDENT_PROFILE,
            self::FACULTY_ACCOUNT,
            self::STAFF_ACCOUNT,
            self::STUDENT_PROFILE_CHANGE_REQUEST,
            self::AUDIT_LOG,
            self::FACULTY_DIRECTORY,
            self::FACULTY_WORKFORCE_PROFILE,
            self::ENROLLMENT,
            self::ASSESSMENT,
            self::ACCOUNT_PAYMENT,
            self::ACADEMIC_GRADE,
            self::QUEUE_TICKET,
            self::WITHDRAWAL_REQUEST,
            self::TRANSFEREE_CREDIT,
            self::TOR_DOCUMENT,
            self::ACADEMIC_TERM,
            self::SUBJECT_OFFERING,
            self::ACADEMIC_TERM_WORKFLOW,
            self::SECTION_PLAN,
            self::ACADEMIC_TERM_YEAR_LEVEL_WINDOW,
            self::ENROLLMENT_CHANGE_REQUEST,
            self::STUDENT_SCHEDULE_PREFERENCE,
            self::QUEUE_CYCLE,
            self::QUEUE_KIOSK_CREDENTIAL,
            self::ENROLLMENT_STATUS_DASHBOARD,
            self::ENROLLMENT_SUBJECT_WAIVER,
            self::STUDENT_ADMISSION_REQUIREMENT,
            self::ADMISSION_REQUIREMENT_TYPE,
            self::SECTION_CHANGE_REQUEST,
            self::PROGRAM_SHIFT,
            self::FEE_SCHEDULE,
            self::PAYMENT_DISCOUNT_SETTING,
            self::FACULTY_LOAD_LIMIT,
            self::FACULTY_LOAD_OVERRIDE,
            self::USER_ACCOUNT,
            self::STUDENT_DEMOGRAPHICS,
            self::CHED_REPORT,
            self::PRIVACY_NOTICE_ACCEPTANCE,
        ];
    }
}
