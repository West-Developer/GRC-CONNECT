<?php

namespace App\Actions\Identity;

use App\Actions\Privacy\RecordPrivacyNoticeAcceptance;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumVersion;
use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\PersonName;
use App\Domain\Identity\StudentAddress;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\StudentAdmissionRequirement;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates the User account and StudentProfile together (PRD §3.2: "Create
 * new student accounts and initial profiles") — a StudentProfile must never
 * exist without its User, or vice versa, so both rows are written in one
 * transaction.
 */
final class ProvisionStudent
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly ListApplicableAdmissionRequirements $applicableRequirements,
        private readonly AssignStudentNumber $assignStudentNumber,
        private readonly RecordPrivacyNoticeAcceptance $recordPrivacyNotice,
    ) {}

    /**
     * @param  array{first_name: string, middle_name?: ?string, last_name: string, suffix?: ?string, email: string, gender: string, birth_date: string, citizenship: string, contact_number: string, street_barangay: string, town_city: string, province: string, has_existing_student_number?: bool, student_number?: ?string, program_id: int, year_level: int, student_type: string, financial_status?: ?string, requirement_type_ids?: ?list<int>, privacy_notice_version?: ?string}  $data
     */
    public function handle(
        array $data,
        User $actor,
        AuditRequestContext $context,
    ): StudentProfile {
        return DB::transaction(function () use ($data, $actor, $context): StudentProfile {
            $currentTerm = AcademicTerm::query()
                ->where('status', AcademicTermStatus::SemesterOngoing)
                ->first();
            if ($currentTerm === null) {
                throw ValidationException::withMessages([
                    'entry_year' => 'No academic term is currently ongoing; entry year cannot be determined.',
                ]);
            }
            $entryYear = (int) substr($currentTerm->school_year, 0, 4);
            $yearLevel = (int) $data['year_level'];

            $curriculum = CurriculumVersion::resolveForEntryYear(
                Curriculum::query()
                    ->where('program_id', $data['program_id'])
                    ->orderByDesc('effective_start_year')
                    ->get(),
                $entryYear,
            );
            if ($curriculum === null) {
                throw ValidationException::withMessages([
                    'entry_year' => 'No curriculum version is configured for this program and entry year.',
                ]);
            }

            $firstName = (string) PersonName::normalizeNamePart($data['first_name']);
            // Admission types the full middle name; every display of the name shows only its initial.
            $middle = PersonName::middle($data['middle_name'] ?? null, null, null);
            $lastName = (string) PersonName::normalizeNamePart($data['last_name']);
            $suffix = PersonName::normalizeSuffix($data['suffix'] ?? null);

            $user = User::create([
                'name' => PersonName::compose($firstName, $middle['middle_initial'], $lastName, $suffix),
                'first_name' => $firstName,
                'middle_name' => $middle['middle_name'],
                'middle_initial' => $middle['middle_initial'],
                'last_name' => $lastName,
                'suffix' => $suffix,
                'email' => $data['email'],
                'password' => Str::random(64),
                'role' => UserRole::Student,
                'status' => UserStatus::Disabled,
                'account_setup_completed_at' => null,
            ]);

            // Admission no longer chooses Regular or Irregular: the system derives it from the student's
            // record when there is one (ADR 0021, 0047), so every new account starts Regular.
            $category = EnrollmentCategory::Regular;
            $studentType = StudentType::from($data['student_type']);
            // The checklist is the evidence of what was handed in. The account counts as "requirements
            // verified" only when every requirement that applies was ticked (or, for an older caller that
            // sends no list, when it confirmed so); otherwise the missing ones stay on the checklist.
            $ticked = isset($data['requirement_type_ids'])
                ? array_map('intval', $data['requirement_type_ids'])
                : null;
            $requirementsComplete = $ticked === null
                || array_diff($this->applicableRequirements->applicableIds($studentType), $ticked) === [];

            // Admission typed the number a Returnee or an Existing Student already has, or the
            // next sequential number is taken inside this transaction (ADR 0042).
            $hasExistingNumber = (bool) ($data['has_existing_student_number'] ?? false);
            $studentNumber = $hasExistingNumber
                ? (string) $data['student_number']
                : $this->assignStudentNumber->handle();

            $profile = StudentProfile::create([
                'user_id' => $user->id,
                'student_number' => $studentNumber,
                'program_id' => $data['program_id'],
                'curriculum_id' => $curriculum->id,
                'entry_year' => $entryYear,
                'year_level' => $yearLevel,
                // Never written to enrollment_category_derived_at, which is reserved for ADR 0021's
                // separate, later, grade-based per-term reclassification.
                'enrollment_category' => $category->value,
                'student_type' => $studentType->value,
                'admission_status' => AdmissionStatus::Admitted,
                'academic_standing' => AcademicStanding::Good,
                'financial_status' => $data['financial_status'] ?? null,
                // The details the CHED forms ask for, typed by Admission (ADR 0049): real values, so no
                // field is marked as generated. `address` is the three address parts written as one text.
                'gender' => $data['gender'],
                'birth_date' => $data['birth_date'],
                'citizenship' => $data['citizenship'],
                'contact_number' => $data['contact_number'],
                'street_barangay' => $data['street_barangay'],
                'town_city' => $data['town_city'],
                'province' => $data['province'],
                'address' => StudentAddress::compose($data['street_barangay'], $data['town_city'], $data['province']),
                'demographics_demo_fields' => null,
                'requirements_verified_at' => $requirementsComplete ? now() : null,
                'requirements_verified_by' => $requirementsComplete ? $actor->id : null,
                // A Transferee or Returnee has 7 days to get the enrollment approved (ADR 0048).
                'enrollment_deadline_at' => $studentType->hasEnrollmentDeadline()
                    ? now()->addDays(StudentType::ENROLLMENT_DEADLINE_DAYS)
                    : null,
            ]);
            $profile->refresh();

            // Admission confirmed the applicant was shown the Data Privacy Notice before their details were
            // saved (ADR 0055). Kept with the account in this transaction, so no account exists without it.
            // The student still agrees themself at first sign-in.
            if (($data['privacy_notice_version'] ?? null) !== null) {
                $this->recordPrivacyNotice->recordShownByStaff($user, $actor, $context);
            }

            // The requirements Admission ticked off on the Create Account form are recorded as
            // handed in: the same rows the student's own checklist reads (ADR 0037).
            $submittedAt = now();
            foreach (array_unique(array_map('intval', $data['requirement_type_ids'] ?? [])) as $requirementTypeId) {
                StudentAdmissionRequirement::create([
                    'student_profile_id' => $profile->id,
                    'requirement_type_id' => $requirementTypeId,
                    'is_submitted' => true,
                    'submitted_at' => $submittedAt,
                    'recorded_by' => $actor->id,
                ]);
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::STUDENT_PROFILE_PROVISIONED,
                AuditableType::STUDENT_PROFILE,
                $profile->id,
                null,
                [
                    'user_id' => $profile->user_id,
                    'student_profile_id' => $profile->id,
                    'role' => $user->role->value,
                    'program_id' => $profile->program_id,
                    'curriculum_id' => $profile->curriculum_id,
                    'entry_year' => $profile->entry_year,
                    'year_level' => $profile->year_level,
                    'enrollment_category' => $profile->enrollment_category,
                    'student_type' => $profile->student_type?->value,
                    'admission_status' => $profile->admission_status->value,
                    'academic_standing' => $profile->academic_standing->value,
                    'financial_status' => $profile->financial_status?->value,
                    'student_number_source' => $hasExistingNumber ? 'existing' : 'assigned',
                ],
                null,
                $context,
            );

            return $profile;
        });
    }
}
