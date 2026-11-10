<?php

namespace App\Actions\Academic;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Notifications\NotificationType;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use App\Support\Notifications\ProgramChairRecipients;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A Student sends their whole saved list to the Program Chair (ADR 0026,
 * amended): every `draft` of theirs becomes `pending` with `submitted_at`, and
 * the Program Chairs of the student's college get one notice with the count.
 *
 * Idempotent: the student's profile row is locked first, so two quick taps run
 * one after the other, and the second finds no draft, changes nothing, notifies
 * nobody and returns an empty collection. Only the caller's own drafts are ever
 * touched: the student is pinned to the caller, never read from the payload.
 */
final readonly class SubmitTransfereeCredits
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
    ) {}

    /**
     * @return Collection<int, TransfereeCredit> the credits this call sent (empty on a repeat)
     */
    public function execute(User $actor, AuditRequestContext $context): Collection
    {
        $student = StudentProfile::query()->with('program')->where('user_id', $actor->id)->first();

        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Your student record could not be found.']);
        }

        return DB::transaction(function () use ($student, $actor, $context): Collection {
            // The profile row is the lock for this student's whole list.
            StudentProfile::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            $drafts = TransfereeCredit::query()
                ->where('student_id', $student->id)
                ->where('status', TransfereeCreditStatus::Draft->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($drafts->isEmpty()) {
                return $drafts;
            }

            $submittedAt = now();

            foreach ($drafts as $credit) {
                $before = ['status' => $credit->status->value];

                $credit->forceFill([
                    'status' => TransfereeCreditStatus::Pending,
                    'submitted_at' => $submittedAt,
                ])->save();

                $this->auditRecorder->record(
                    $actor,
                    AuditAction::TRANSFEREE_CREDIT_SUBMITTED,
                    AuditableType::TRANSFEREE_CREDIT,
                    $credit->id,
                    $before,
                    ['status' => TransfereeCreditStatus::Pending->value],
                    null,
                    $context,
                );
            }

            $count = $drafts->count();

            $this->notificationRecorder->recordMany(
                ProgramChairRecipients::forStudent($student),
                NotificationType::TransfereeCreditRequested,
                sprintf(
                    'Student %s submitted %d %s for credit mapping.',
                    $student->student_number,
                    $count,
                    $count === 1 ? 'subject' : 'subjects',
                ),
            );

            return $drafts->load(['student.user', 'subject']);
        });
    }
}
