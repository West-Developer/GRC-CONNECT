<?php

namespace App\Domain\Reports;

/**
 * The four official reports the Registrar Head hands to CHED (ADR 0049). The key is what the API
 * route and the file name use; the column labels are the template's own header texts, so the CSV
 * and the preview read like the form.
 */
enum ChedReport: string
{
    case EnrollmentList = 'enrollment-list';
    case NstpEnrollment = 'nstp-enrollment';
    case NstpGraduates = 'nstp-graduates';
    case NstpSummary = 'nstp-summary';

    /**
     * The report's name for a semester. NSTP 1 is taken in the first semester and NSTP 2 in the second,
     * so the NSTP enrollment list is "NSTP 1 Enrollment" or "NSTP 2 Enrollment" by the term it is for.
     */
    public function titleFor(string $semester): string
    {
        return match ($this) {
            self::EnrollmentList => 'CHED Enrollment List',
            self::NstpEnrollment => self::nstpSubjectOf($semester).' Enrollment (Form 2-B)',
            self::NstpGraduates => 'NSTP 2 Graduates (Form 2-B)',
            self::NstpSummary => 'NSTP Summary (Form 2-A)',
        };
    }

    /** `NSTP 1` in the first semester, `NSTP 2` in the second, plain `NSTP` for any other term. */
    public static function nstpSubjectOf(string $semester): string
    {
        return match (strtolower(trim($semester))) {
            '1st' => 'NSTP 1',
            '2nd' => 'NSTP 2',
            default => 'NSTP',
        };
    }

    /** One row per student (the three lists) versus the one-row school summary. */
    public function isList(): bool
    {
        return $this !== self::NstpSummary;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function columns(): array
    {
        return match ($this) {
            self::EnrollmentList => [
                ['key' => 'no', 'label' => 'No.'],
                ['key' => 'program', 'label' => 'Programs'],
                ['key' => 'major', 'label' => 'Major'],
                ['key' => 'student_number', 'label' => 'STUDENT NUMBER'],
                ['key' => 'first_name', 'label' => 'FIRST NAME'],
                ['key' => 'middle_name', 'label' => 'MIDDLE NAME'],
                ['key' => 'surname', 'label' => 'SURNAME'],
                ['key' => 'name_extension', 'label' => 'Name Extension'],
                ['key' => 'citizenship', 'label' => 'Citizenship'],
                ['key' => 'gender', 'label' => 'GENDER'],
                ['key' => 'year_level', 'label' => 'Year Level'],
                ['key' => 'subjects', 'label' => 'SUBJECTS ENROLLED FOLLOWED BY UNITS'],
                ['key' => 'units', 'label' => 'NO. OF UNITS'],
                ['key' => 'remarks', 'label' => 'REMARKS'],
            ],
            self::NstpEnrollment, self::NstpGraduates => [
                ['key' => 'no', 'label' => 'No.'],
                ['key' => 'serial_no', 'label' => 'Serial No.'],
                ['key' => 'surname', 'label' => 'Surname'],
                ['key' => 'first_name', 'label' => 'First Name'],
                ['key' => 'middle_name', 'label' => 'Middle Name'],
                ['key' => 'course', 'label' => 'Course/Program (Write in full)'],
                ['key' => 'gender', 'label' => 'Gender'],
                ['key' => 'birthdate', 'label' => 'Birthdate (ex. 11/25/1992)'],
                ['key' => 'street_barangay', 'label' => 'Street/Barangay Address'],
                ['key' => 'town_city', 'label' => 'Town/City Address'],
                ['key' => 'province', 'label' => 'Provincial Address'],
                ['key' => 'contact_number', 'label' => 'Contact Number Telephone/Mobile'],
                ['key' => 'email', 'label' => 'Email Address'],
            ],
            self::NstpSummary => [],
        };
    }
}
