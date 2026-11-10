<?php

namespace App\Actions\Enrollment;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\StudentQueueView;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;

/**
 * Read-only aggregate for the student's own "where am I in the queue" view
 * (PRD §5.3 FR-FIN-006). The board (now serving / upcoming numbers) comes from
 * `BuildQueueBoard`, which never exposes another student's identity — only
 * ticket numbers, the same privacy convention `QueueTicket::position()` already
 * follows for "how many ahead of me".
 */
final readonly class BuildStudentQueueView
{
    public function __construct(private BuildQueueBoard $queueBoard) {}

    public function execute(User $actor): StudentQueueView
    {
        $student = StudentProfile::query()->where('user_id', $actor->id)->first();
        $term = AcademicTerm::query()->where('status', AcademicTermStatus::SemesterOngoing)->first();

        $enrollment = ($student === null || $term === null) ? null : Enrollment::query()
            ->with('queueTicket')
            ->where('student_id', $student->id)
            ->where('academic_term_id', $term->id)
            ->orderByDesc('id')
            ->first();

        $stage = $this->stageFor($enrollment);
        $ticket = $enrollment?->queueTicket;
        // A pay-first student's number belongs to their profile, not to an enrollment (they get it before they
        // choose subjects). It stays visible until the Cashier has taken their first payment; after that it is spent.
        if ($ticket === null && $student?->isAwaitingFirstPayment()) {
            $ticket = $student->queueTicket;
        }
        $canClaim = $stage === 'pending_payment' && $ticket === null;

        $board = $this->queueBoard->execute();

        return new StudentQueueView($stage, $canClaim, $ticket, $board->nowServingTicketNumber, $board->upcomingTicketNumbers, $board->cutOffToday);
    }

    private function stageFor(?Enrollment $enrollment): string
    {
        if ($enrollment === null) {
            return 'no_active_enrollment';
        }

        return match ($enrollment->status) {
            EnrollmentStatus::PendingProgramHeadApproval => 'pending_program_head_approval',
            EnrollmentStatus::PendingStudentReview => 'pending_student_review',
            EnrollmentStatus::AwaitingFirstPayment => 'awaiting_first_payment',
            EnrollmentStatus::Draft, EnrollmentStatus::PendingRegistrarApproval => 'pending_registrar_approval',
            EnrollmentStatus::PendingPayment => 'pending_payment',
            EnrollmentStatus::Enrolled => 'enrolled',
            EnrollmentStatus::Rejected, EnrollmentStatus::Cancelled, EnrollmentStatus::Withdrawn => 'no_active_enrollment',
        };
    }
}
