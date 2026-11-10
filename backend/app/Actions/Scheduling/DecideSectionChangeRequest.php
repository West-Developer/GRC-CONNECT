<?php

namespace App\Actions\Scheduling;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Notifications\NotificationType;
use App\Domain\Scheduling\PublishedSectionLock;
use App\Domain\Scheduling\SectionAssignmentConflicts;
use App\Domain\Scheduling\SectionChangeRequestStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\Notification;
use App\Models\Section;
use App\Models\SectionChangeRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Approve or reject a section change request (Registrar Head), or withdraw it
 * (the Program Head who filed it). ADR 0032.
 *
 * Approving applies the change through `UpdateSection` in the same transaction
 * as the decision, after re-checking the section against what the requester
 * saw and against every clash, so nothing half-applies. Repeating the same
 * decision on an already-decided request returns it unchanged: no second
 * update, audit row, or notification.
 */
final readonly class DecideSectionChangeRequest
{
    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const CANCEL = 'cancel';

    public function __construct(
        private AuditRecorder $auditRecorder,
        private UpdateSection $updateSection,
        private PublishedSectionLock $lock,
        private SectionAssignmentConflicts $conflicts,
    ) {}

    public function execute(
        User $actor,
        SectionChangeRequest $changeRequest,
        string $decision,
        ?string $reason,
        AuditRequestContext $context,
    ): SectionChangeRequest {
        return DB::transaction(function () use ($actor, $changeRequest, $decision, $reason, $context): SectionChangeRequest {
            $request = SectionChangeRequest::query()->lockForUpdate()->findOrFail($changeRequest->id);

            $target = match ($decision) {
                self::APPROVE => SectionChangeRequestStatus::Approved,
                self::REJECT => SectionChangeRequestStatus::Rejected,
                self::CANCEL => SectionChangeRequestStatus::Cancelled,
                default => throw new InvalidArgumentException("Unknown section change decision [{$decision}]."),
            };

            if ($request->status === $target) {
                return $this->fresh($request);
            }

            if ($request->status !== SectionChangeRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'action' => "This request was already {$request->status->value}.",
                ]);
            }

            if ($decision === self::REJECT && ! filled($reason)) {
                throw ValidationException::withMessages([
                    'decision_reason' => 'Give the Program Head a reason for turning the change down.',
                ]);
            }

            $section = Section::query()->with('subject')->lockForUpdate()->findOrFail($request->section_id);

            if ($decision === self::APPROVE) {
                $this->apply($actor, $request, $section, $context);
            }

            $before = ['status' => $request->status->value];
            $request->update([
                'status' => $target,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => filled($reason) ? $reason : null,
            ]);

            $this->auditRecorder->record(
                $actor,
                match ($decision) {
                    self::APPROVE => AuditAction::SECTION_CHANGE_APPROVED,
                    self::REJECT => AuditAction::SECTION_CHANGE_REJECTED,
                    default => AuditAction::SECTION_CHANGE_CANCELLED,
                },
                AuditableType::SECTION_CHANGE_REQUEST,
                $request->id,
                $before,
                ['status' => $target->value, 'section_id' => $request->section_id] + $request->new_values,
                filled($reason) ? $reason : null,
                $context,
            );

            if ($decision !== self::CANCEL) {
                Notification::create([
                    'user_id' => $request->requested_by,
                    'type' => $decision === self::APPROVE
                        ? NotificationType::SectionChangeApproved
                        : NotificationType::SectionChangeRejected,
                    'message' => sprintf(
                        'Your schedule change for %s section %s was %s.%s',
                        $section->subject->code,
                        $section->section_code,
                        $decision === self::APPROVE ? 'approved and applied' : 'not approved',
                        filled($reason) ? " Registrar Head: {$reason}" : '',
                    ),
                ]);
            }

            return $this->fresh($request);
        });
    }

    private function apply(User $actor, SectionChangeRequest $request, Section $section, AuditRequestContext $context): void
    {
        if ($section->status !== SectionStatus::Published) {
            throw ValidationException::withMessages([
                'section' => 'This section is no longer published, so the request cannot be applied. Reject it and ask for a new one if needed.',
            ]);
        }

        foreach ($request->old_values as $field => $seen) {
            if ($this->lock->normalized($field, $seen) !== $this->lock->normalized($field, $this->lock->current($section, $field))) {
                throw ValidationException::withMessages([
                    'section' => 'This section changed after the request was made. Reject it and ask the Program Head to send a new one.',
                ]);
            }
        }

        $problems = SectionChangeApplication::problemsFor($section, $request->new_values, $this->conflicts);
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $merged = SectionChangeApplication::merged($section, $request->new_values);

        $this->updateSection->execute($actor, [
            'academic_term_id' => $section->academic_term_id,
            'subject_id' => $section->subject_id,
            'section_code' => $section->section_code,
            'professor_id' => $section->professor_id,
            'schedule_days' => $merged['schedule_days'],
            'starts_at_time' => $merged['starts_at_time'],
            'ends_at_time' => $merged['ends_at_time'],
            'room' => $merged['room'],
            'modality' => $merged['modality'],
            'capacity' => $merged['capacity'],
            'viability_threshold' => $merged['viability_threshold'],
            'status' => $section->status->value,
            'override_reason' => Str::limit($request->reason, 1000, ''),
        ], $section, $context);
    }

    private function fresh(SectionChangeRequest $request): SectionChangeRequest
    {
        return $request->refresh()->load(['section.subject', 'requester', 'decider']);
    }
}
