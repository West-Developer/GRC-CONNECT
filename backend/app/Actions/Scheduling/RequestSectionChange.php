<?php

namespace App\Actions\Scheduling;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Domain\Scheduling\CanonicalScheduleDays;
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
use Illuminate\Validation\ValidationException;

/**
 * A Program Head asks the Registrar Head to change a published section
 * (ADR 0032). The request keeps the section as the Program Head saw it, so an
 * approval can tell if it moved in the meantime, and it is refused up front
 * when the proposed slot would clash: the Registrar Head should never receive
 * a request that cannot be applied.
 *
 * One pending request per section: asking again while one is waiting is
 * refused rather than queued, so a section never has two competing edits.
 */
final readonly class RequestSectionChange
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private PublishedSectionLock $lock,
        private SectionAssignmentConflicts $conflicts,
        private CanonicalScheduleDays $canonicalScheduleDays,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  only PublishedSectionLock::CHANGEABLE_FIELDS
     */
    public function execute(
        User $actor,
        Section $section,
        array $changes,
        string $reason,
        AuditRequestContext $context,
    ): SectionChangeRequest {
        return DB::transaction(function () use ($actor, $section, $changes, $reason, $context): SectionChangeRequest {
            $section = Section::query()->lockForUpdate()->findOrFail($section->id);

            if ($section->status !== SectionStatus::Published) {
                throw ValidationException::withMessages([
                    'section' => 'Only a published section needs a change request. Edit this section directly.',
                ]);
            }

            $alreadyPending = SectionChangeRequest::query()
                ->where('section_id', $section->id)
                ->where('status', SectionChangeRequestStatus::Pending)
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'section' => 'A change request for this section is already waiting for the Registrar Head. Withdraw it before sending another.',
                ]);
            }

            $newValues = [];
            $oldValues = [];
            foreach ($changes as $field => $value) {
                $normalized = $field === 'schedule_days'
                    ? $this->canonicalScheduleDays->normalize(is_string($value) ? $value : null)
                    : (is_string($value) && trim($value) === '' ? null : $value);

                if ($this->lock->normalized($field, $normalized) === $this->lock->normalized($field, $this->lock->current($section, $field))) {
                    continue;
                }

                $newValues[$field] = $normalized;
                $oldValues[$field] = $this->lock->current($section, $field);
            }

            if ($newValues === []) {
                throw ValidationException::withMessages([
                    'changes' => 'None of these values differ from the published schedule.',
                ]);
            }

            $errors = SectionChangeApplication::problemsFor($section, $newValues, $this->conflicts);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $request = SectionChangeRequest::create([
                'section_id' => $section->id,
                'requested_by' => $actor->id,
                'status' => SectionChangeRequestStatus::Pending,
                'reason' => $reason,
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::SECTION_CHANGE_REQUESTED,
                AuditableType::SECTION_CHANGE_REQUEST,
                $request->id,
                $oldValues,
                $newValues,
                $reason,
                $context,
            );

            $section->loadMissing('subject');
            User::query()
                ->where('role', UserRole::RegistrarHead)
                ->get()
                ->each(fn (User $head) => Notification::create([
                    'user_id' => $head->id,
                    'type' => NotificationType::SectionChangeRequested,
                    'message' => sprintf(
                        '%s asked to change the schedule of %s section %s. Review it under Schedule Change Requests.',
                        $actor->name,
                        $section->subject->code,
                        $section->section_code,
                    ),
                ]));

            return $request->load(['section.subject', 'requester']);
        });
    }
}
