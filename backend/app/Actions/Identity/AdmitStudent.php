<?php

namespace App\Actions\Identity;

use App\Actions\Enrollment\IssueFreshmanQueueTicket;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\AdmittedStudent;
use App\Domain\Identity\StudentType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admission records a student. For a freshman that is two things that must stand or fall
 * together (ADR 0043): the account, and the queue number they take to the Cashier before
 * they have paid or can sign in. They are one transaction, so a failure issuing the queue
 * number leaves nothing behind — no account the same email can then not be used for
 * ("email already taken"), no student number used up, and nobody stuck without a number.
 *
 * Emails are not sent here: they leave the building and cannot be rolled back, so the
 * caller sends them after this has committed.
 */
final readonly class AdmitStudent
{
    public function __construct(
        private ProvisionStudent $provisioner,
        private IssueFreshmanQueueTicket $issueQueueTicket,
    ) {}

    /**
     * @param  array{first_name: string, middle_name?: ?string, last_name: string, suffix?: ?string, email: string, gender: string, birth_date: string, citizenship: string, contact_number: string, street_barangay: string, town_city: string, province: string, has_existing_student_number?: bool, student_number?: ?string, program_id: int, year_level: int, student_type: string, financial_status?: ?string, requirement_type_ids?: ?list<int>, privacy_notice_version?: ?string}  $data
     */
    public function handle(array $data, User $actor, AuditRequestContext $context): AdmittedStudent
    {
        return DB::transaction(function () use ($data, $actor, $context): AdmittedStudent {
            $profile = $this->provisioner->handle($data, $actor, $context);

            return new AdmittedStudent(
                $profile,
                $profile->student_type === StudentType::Freshman
                    ? $this->issueQueueTicket->execute($profile, $actor, $context)
                    : null,
            );
        });
    }
}
