<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Enrollment\FreshmanPaymentRules;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read StudentProfile $resource
 */
final class StudentProfileResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly — in
     * particular, no password or credential ever appears here.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     user_id: int,
     *     student_number: string,
     *     name: string,
     *     first_name: string,
     *     middle_name: ?string,
     *     middle_initial: ?string,
     *     last_name: string,
     *     suffix: ?string,
     *     email: string,
     *     address: ?string,
     *     gender: ?string,
     *     birth_date: ?string,
     *     citizenship: ?string,
     *     contact_number: ?string,
     *     street_barangay: ?string,
     *     town_city: ?string,
     *     province: ?string,
     *     generated_fields: list<string>,
     *     program_id: int,
     *     program_code: string,
     *     program_name: string,
     *     curriculum_id: int,
     *     entry_year: ?int,
     *     curriculum_name: string,
     *     curriculum_effective_school_year: string,
     *     year_level: int,
     *     enrollment_category: ?string,
     *     student_type: ?string,
     *     student_type_label: ?string,
     *     admission_status: string,
     *     admission_status_label: string,
     *     academic_standing: string,
     *     academic_standing_label: string,
     *     financial_status: ?string,
     *     financial_status_label: ?string,
     *     requirements_verified_at: ?string,
     *     academic_setup_editable: bool,
     *     account_setup_status: 'awaiting_payment'|'pending'|'active',
     *     invitation_delivery_status: 'not_sent'|'sent'|'failed',
     *     freshman_payment_confirmed_at: ?string,
     *     enrollment_deadline_at: ?string,
     *     freshman_status: 'awaiting_payment'|'awaiting_section'|'enrolling'|null,
     *     advance_payment_held: bool,
     *     queue_ticket_number: ?string,
     *     queue_link_delivery_status: ?('not_sent'|'sent'|'failed')
     * }
     */
    public function toArray(Request $request): array
    {
        $curriculum = $this->resource->curriculum;
        $user = $this->resource->user;
        $program = $this->resource->program;
        $queueTicket = $this->resource->queueTicket;

        return [
            'type' => 'student_profile',
            'id' => $this->resource->id,
            'user_id' => $this->resource->user_id,
            'student_number' => $this->resource->student_number,
            'name' => $user->name,
            'first_name' => $user->first_name ?? '',
            // Only Admission and the student themself ever get the full middle name; everyone else, and every
            // display of the name, has the initial (ADR 0053).
            'middle_name' => $this->mayReadFullMiddleName($request) ? $user->middle_name : null,
            'middle_initial' => $user->middle_initial,
            'last_name' => $user->last_name ?? '',
            'suffix' => $user->suffix,
            'email' => $user->email,
            'address' => $this->resource->address,
            // The details the CHED forms ask for (ADR 0049). `generated_fields` names the ones that were
            // generated for the demo and not typed by Admission, so the screen can ask for the real value.
            'gender' => $this->resource->gender?->value,
            'birth_date' => $this->resource->birth_date?->format('Y-m-d'),
            'citizenship' => $this->resource->citizenship,
            'contact_number' => $this->resource->contact_number,
            'street_barangay' => $this->resource->street_barangay,
            'town_city' => $this->resource->town_city,
            'province' => $this->resource->province,
            'generated_fields' => $this->resource->demographics_demo_fields ?? [],
            'program_id' => $this->resource->program_id,
            'program_code' => $program->code,
            'program_name' => $program->name,
            'curriculum_id' => $this->resource->curriculum_id,
            'entry_year' => $this->resource->entry_year,
            'curriculum_name' => $curriculum->name,
            'curriculum_effective_school_year' => $curriculum->effective_school_year,
            'curriculum_max_units' => $curriculum->effectiveMaxUnits(),
            'curriculum_default_max_units' => $curriculum->defaultMaxUnits(),
            'year_level' => $this->resource->year_level,
            'enrollment_category' => $this->resource->enrollment_category,
            'student_type' => $this->resource->student_type?->value,
            'student_type_label' => $this->resource->student_type?->label(),
            'admission_status' => $this->resource->admission_status->value,
            'admission_status_label' => $this->resource->admission_status->label(),
            'academic_standing' => $this->resource->academic_standing->value,
            'academic_standing_label' => $this->resource->academic_standing->label(),
            'financial_status' => $this->resource->financial_status?->value,
            'financial_status_label' => $this->resource->financial_status?->label(),
            'requirements_verified_at' => $this->resource->requirements_verified_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'academic_setup_editable' => ! ((bool) ($this->resource->enrollments_exists ?? false)),
            // 'awaiting_payment': a freshman whose first payment the Cashier has not
            // confirmed yet — no setup email is sent until then (see
            // `SendStudentAccountSetupInvitation`).
            'account_setup_status' => match (true) {
                $user->account_setup_completed_at !== null => 'active',
                $this->resource->isFreshmanAwaitingPayment() => 'awaiting_payment',
                default => 'pending',
            },
            'invitation_delivery_status' => match (true) {
                $user->account_setup_invitation_failed_at !== null
                    && ($user->account_setup_invitation_sent_at === null
                        || $user->account_setup_invitation_failed_at->greaterThanOrEqualTo($user->account_setup_invitation_sent_at)) => 'failed',
                $user->account_setup_invitation_sent_at !== null => 'sent',
                default => 'not_sent',
            },
            'freshman_payment_confirmed_at' => $this->resource->freshman_payment_confirmed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            // While set, a Transferee or Returnee sees only Enrollment and is deactivated at this moment
            // unless the Registrar has approved their enrollment (ADR 0048).
            'enrollment_deadline_at' => $this->resource->enrollment_deadline_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'freshman_status' => $this->freshmanStatus(),
            'advance_payment_held' => $this->advancePaymentHeld(),
            'queue_ticket_number' => $queueTicket?->ticket_number,
            'queue_link_delivery_status' => match (true) {
                $queueTicket === null => null,
                $queueTicket->link_failed_at !== null
                    && ($queueTicket->link_sent_at === null
                        || $queueTicket->link_failed_at->greaterThanOrEqualTo($queueTicket->link_sent_at)) => 'failed',
                $queueTicket->link_sent_at !== null => 'sent',
                default => 'not_sent',
            },
        ];
    }

    private function mayReadFullMiddleName(Request $request): bool
    {
        $viewer = $request->user();

        return $viewer instanceof User
            && ($viewer->role === UserRole::AdmissionStaff || $viewer->id === $this->resource->user_id);
    }

    /**
     * Where a freshman is in the pay-first flow (ADR 0043); null for every other student.
     * `awaiting_section`: paid, but no enrollment in progress (every section was full when
     * they paid). `enrolling`: paid, with an enrollment in progress or done.
     *
     * @return 'awaiting_payment'|'awaiting_section'|'enrolling'|null
     */
    private function freshmanStatus(): ?string
    {
        $profile = $this->resource;

        if ($profile->student_type !== StudentType::Freshman) {
            return null;
        }

        if ($profile->freshman_payment_confirmed_at === null) {
            return 'awaiting_payment';
        }

        $hasActiveEnrollment = $profile->getAttribute('active_enrollment_exists')
            ?? $profile->enrollments()
                ->whereNotIn('status', [EnrollmentStatus::Rejected->value, EnrollmentStatus::Cancelled->value, EnrollmentStatus::Withdrawn->value])
                ->exists();

        return $hasActiveEnrollment ? 'enrolling' : 'awaiting_section';
    }

    /**
     * True while a pay-first student's (a freshman's or a transferee's) first payment is held as
     * credit and has not yet been applied to an enrollment (ADR 0043, amended 2026-10-05): the very
     * condition `SettleEnrollmentFromAdvanceCredit` completes an approval from. It lets the student's
     * own screens say that nothing is left to queue for or pay, and it is about the money, not the
     * term: once the credit is applied, or for anyone who never held any, it is false.
     */
    private function advancePaymentHeld(): bool
    {
        $profile = $this->resource;

        if (! $profile->student_type instanceof StudentType
            || ! $profile->student_type->isPayFirst()
            || $profile->freshman_payment_confirmed_at === null) {
            return false;
        }

        $held = $profile->getAttribute('held_advance_payment_sum')
            ?? $profile->accountPayments()->whereNull('enrollment_id')->sum('amount');

        return (float) $held >= (float) FreshmanPaymentRules::minimumAmount();
    }
}
