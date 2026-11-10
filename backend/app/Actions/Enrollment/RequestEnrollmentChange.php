<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentChangeRequestStatus;
use App\Domain\Enrollment\EnrollmentChangeRequestType;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Models\Enrollment;
use App\Models\EnrollmentChangeRequest;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 7: a Student requests an add, a drop, or a section change against
 * their own `enrolled` enrollment, during the add/drop window
 * (`AddDropWindowResolver`). This only records the request as `pending` —
 * it never touches `EnrollmentSubject`/`sections.enrolled_count` itself;
 * that happens only on `TransitionEnrollmentChangeRequest`'s `approve`,
 * mirroring `RequestWithdrawal`'s "create records intent, decide applies
 * it" split.
 *
 * `subject_id` is always derived from whichever section is present, never
 * taken directly from client input, so the stored subject can never
 * disagree with the section(s) actually referenced.
 */
final readonly class RequestEnrollmentChange
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
    ) {}

    public function execute(
        Enrollment $enrollment,
        EnrollmentChangeRequestType $type,
        ?Section $fromSection,
        ?Section $toSection,
        string $reason,
        User $actor,
        AuditRequestContext $context,
    ): EnrollmentChangeRequest {
        return DB::transaction(function () use ($enrollment, $type, $fromSection, $toSection, $reason, $actor, $context): EnrollmentChangeRequest {
            $subjectSource = $toSection ?? $fromSection;
            if ($subjectSource === null) {
                throw new InvalidArgumentException('At least one of $fromSection/$toSection must be provided.');
            }
            $subjectId = $subjectSource->subject_id;

            $request = EnrollmentChangeRequest::create([
                'enrollment_id' => $enrollment->id,
                'type' => $type,
                'subject_id' => $subjectId,
                'from_section_id' => $fromSection?->id,
                'to_section_id' => $toSection?->id,
                'reason' => $reason,
                'status' => EnrollmentChangeRequestStatus::Pending,
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::ENROLLMENT_CHANGE_REQUEST_CREATED,
                AuditableType::ENROLLMENT_CHANGE_REQUEST,
                $request->id,
                null,
                [
                    'enrollment_id' => $enrollment->id,
                    'type' => $type->value,
                    'subject_id' => $subjectId,
                    'from_section_id' => $fromSection?->id,
                    'to_section_id' => $toSection?->id,
                    'status' => $request->status->value,
                ],
                null,
                $context,
            );

            $this->notificationRecorder->recordManyForRole(
                UserRole::RegistrarHead,
                NotificationType::EnrollmentChangeRequestSubmitted,
                "{$enrollment->student->student_number} submitted a {$type->label()} request.",
            );

            return $request->refresh()->load(['enrollment.student', 'subject', 'fromSection', 'toSection']);
        });
    }
}
