<?php

namespace App\Actions\Enrollment;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\StudentQueueView;
use App\Domain\Identity\StudentType;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\QueueTicket;
use App\Models\StudentProfile;

/**
 * The read-only live queue behind a freshman's emailed link (ADR 0044). Same
 * shape as the signed-in student's own view so the frontend reuses its live
 * panel, but the stage is the pay-first student's: still waiting to pay
 * (`freshman_awaiting_payment`) or already paid (`freshman_paid`, after which the
 * account-setup email is the next step). A transferee has the same two stages
 * under `transferee_*` plus `transferee_enrolled` once the Registrar has approved.
 * Only ticket numbers are exposed.
 */
final readonly class BuildPublicQueueView
{
    public function __construct(private BuildQueueBoard $queueBoard) {}

    public function execute(QueueTicket $ticket): StudentQueueView
    {
        $profile = $ticket->studentProfile;
        $board = $this->queueBoard->execute();
        // A transferee gets their own stages: their wording differs (they already have an account and choose their subjects).
        $kind = $profile?->student_type === StudentType::Transferee ? 'transferee' : 'freshman';
        $paid = $profile !== null && $profile->freshman_payment_confirmed_at !== null;
        $stage = $paid ? "{$kind}_paid" : "{$kind}_awaiting_payment";
        // The link outlives the payment: once the Registrar has approved, a transferee must not still read "waits for approval".
        if ($paid && $kind === 'transferee' && $this->isEnrolledThisTerm($profile)) {
            $stage = 'transferee_enrolled';
        }

        return new StudentQueueView(
            $stage,
            false,
            $ticket,
            $board->nowServingTicketNumber,
            $board->upcomingTicketNumbers,
            $board->cutOffToday,
        );
    }

    /** Same rule as the signed-in student's own view: their latest enrollment of the ongoing term. */
    private function isEnrolledThisTerm(StudentProfile $profile): bool
    {
        $term = AcademicTerm::query()->where('status', AcademicTermStatus::SemesterOngoing)->first();
        if ($term === null) {
            return false;
        }

        $latest = Enrollment::query()
            ->where('student_id', $profile->id)
            ->where('academic_term_id', $term->id)
            ->orderByDesc('id')
            ->first();

        return $latest?->status === EnrollmentStatus::Enrolled;
    }
}
