<?php

namespace App\Actions\Dashboard;

use App\Domain\Dashboard\StuckEnrollmentRow;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use Illuminate\Support\Carbon;

/**
 * PRD §3.5 authorizes the Dean to see "stuck-student reports" specifically —
 * the one exception to this phase's aggregate-only rule for Dean/Executive
 * Director. Even so, this returns the minimum identifying field
 * (student_number) and nothing else personal; see StuckEnrollmentRow's
 * docblock.
 *
 * Dwell time (days in the enrollment's current status) is arithmetic over
 * the same four timestamps BuildEnrollmentSummary uses, and is always
 * returned. Whether a row counts as "stuck" is a separate, optional
 * threshold (config('enrollment.dashboard.stuck_threshold_days')) — see
 * that key's docblock for why it ships unset.
 *
 * Scoped to enrollments still *progressing toward* `enrolled`
 * (draft/pending_registrar_approval/pending_payment), not
 * Enrollment::scopeActive()'s broader "not yet terminal" set — an already
 * `enrolled` row has reached the PRD-defined lifecycle's successful
 * endpoint (EnrollmentStatus's own docblock:
 * "draft → pending_registrar_approval → pending_payment → enrolled"), so it
 * is not a candidate for "stuck" under any reading of that lifecycle,
 * without needing to invent a definition to say so.
 */
final readonly class ListStuckEnrollments
{
    private const IN_PROGRESS_STATUSES = [
        EnrollmentStatus::Draft,
        EnrollmentStatus::PendingProgramHeadApproval,
        EnrollmentStatus::PendingStudentReview,
        EnrollmentStatus::AwaitingFirstPayment,
        EnrollmentStatus::PendingRegistrarApproval,
        EnrollmentStatus::PendingPayment,
    ];

    /**
     * @return list<StuckEnrollmentRow>
     */
    public function execute(AcademicTerm $term): array
    {
        $thresholdDays = config('enrollment.dashboard.stuck_threshold_days');
        $now = Carbon::now('UTC');

        $rows = Enrollment::query()
            ->whereIn('status', array_map(fn (EnrollmentStatus $status): string => $status->value, self::IN_PROGRESS_STATUSES))
            ->where('academic_term_id', $term->id)
            ->with('student')
            ->orderBy('submitted_at')
            ->get()
            ->map(function (Enrollment $enrollment) use ($now, $thresholdDays): StuckEnrollmentRow {
                $statedSince = match ($enrollment->status) {
                    EnrollmentStatus::PendingPayment => $enrollment->registrar_decided_at,
                    EnrollmentStatus::PendingRegistrarApproval,
                    // Waiting on the student's first payment since the review finished (or since they submitted).
                    EnrollmentStatus::AwaitingFirstPayment => $enrollment->program_head_decided_at ?? $enrollment->submitted_at,
                    // Waiting on the student since the Program Chair last sent changes (the row was last touched then).
                    EnrollmentStatus::PendingStudentReview => $enrollment->updated_at,
                    EnrollmentStatus::PendingProgramHeadApproval, EnrollmentStatus::Draft => $enrollment->submitted_at,
                    default => $enrollment->submitted_at,
                } ?? $enrollment->submitted_at ?? $now;

                $daysInStatus = (int) $statedSince->diffInDays($now);
                $isFlagged = is_numeric($thresholdDays) && $daysInStatus >= (int) $thresholdDays;

                return new StuckEnrollmentRow(
                    enrollmentId: $enrollment->id,
                    studentNumber: $enrollment->student->student_number,
                    status: $enrollment->status->value,
                    statusLabel: $enrollment->status->label(),
                    daysInStatus: $daysInStatus,
                    isFlagged: $isFlagged,
                );
            })
            ->sortByDesc(fn (StuckEnrollmentRow $row): int => $row->daysInStatus)
            ->values()
            ->all();

        return array_values($rows);
    }
}
