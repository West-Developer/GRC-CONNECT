<?php

namespace App\Actions\Scheduling;

use App\Actions\Enrollment\NotifyAwaitingFreshmen;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\CapacitySource;
use App\Domain\Scheduling\CanonicalScheduleDays;
use App\Domain\Scheduling\SectionStatus;
use App\Models\Notification;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateSection
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly CanonicalScheduleDays $canonicalScheduleDays,
        private readonly NotifyAwaitingFreshmen $notifyAwaitingFreshmen,
    ) {}

    /**
     * @param  array{
     *     academic_term_id: int,
     *     subject_id: int,
     *     section_code: string,
     *     professor_id: ?int,
     *     schedule_days: ?string,
     *     starts_at_time: ?string,
     *     ends_at_time: ?string,
     *     room: ?string,
     *     modality: ?string,
     *     capacity: int,
     *     viability_threshold: ?int,
     *     status: string,
     *     override_reason?: ?string
     * }  $validatedData
     */
    public function execute(
        User $actor,
        array $validatedData,
        Section $section,
        AuditRequestContext $context,
    ): Section {
        return DB::transaction(function () use ($actor, $validatedData, $section, $context): Section {
            $beforeValues = self::snapshot($section);
            $capacity = (int) $validatedData['capacity'];

            // A section can never seat fewer students than are already
            // enrolled in it — that would make `remainingSeats()` lie and
            // strand students the pool would then treat as over capacity.
            if ($capacity < $section->enrolled_count) {
                throw ValidationException::withMessages([
                    'capacity' => "Capacity cannot be below the {$section->enrolled_count} students already enrolled in this section.",
                ]);
            }

            // Typing a different capacity claims this section from its
            // year-level plan, so a later `SaveSectionPlan::release()` will
            // not overwrite the Chair's deliberate figure.
            $capacitySource = $capacity === $section->capacity
                ? $section->capacity_source
                : CapacitySource::Manual;

            $section->update([
                'academic_term_id' => $validatedData['academic_term_id'],
                'subject_id' => $validatedData['subject_id'],
                'section_code' => $validatedData['section_code'],
                'professor_id' => $validatedData['professor_id'],
                'schedule_days' => $this->canonicalScheduleDays->normalize($validatedData['schedule_days']),
                'starts_at_time' => $validatedData['starts_at_time'],
                'ends_at_time' => $validatedData['ends_at_time'],
                'room' => $validatedData['room'],
                'modality' => $validatedData['modality'] ?? null,
                'capacity' => $capacity,
                'capacity_source' => $capacitySource,
                'viability_threshold' => $validatedData['viability_threshold'],
                'status' => $validatedData['status'],
                'manual_override_reason' => $validatedData['override_reason'] ?? $section->manual_override_reason,
            ]);
            $section->refresh();

            // Notify a professor the first time they are assigned to a
            // section (previous professor_id was null, new one is not).
            // A reassignment from one professor to another is intentionally
            // not notified here — only the initial assignment is treated as
            // a meaningful event that warrants a notification.
            $previousProfessorId = $beforeValues['professor_id'];
            $newProfessorId = $section->professor_id;
            if ($previousProfessorId === null && $newProfessorId !== null) {
                $assignedProfessor = User::find($newProfessorId);
                if ($assignedProfessor !== null) {
                    Notification::create([
                        'user_id' => $assignedProfessor->id,
                        'type' => NotificationType::SectionAssigned,
                        'message' => sprintf(
                            'You have been assigned to teach section %s. Please check your teaching schedule.',
                            $section->section_code,
                        ),
                    ]);
                }
            }

            // A published section is final for the Program Head, who may still
            // swap the professor without approval (ADR 0032). The Registrar Head
            // hears about it and the audit log says so in its own action.
            $reassignedAfterPublish = in_array($actor->role, [UserRole::ProgramChair, UserRole::Dean], true)
                && $beforeValues['status'] === SectionStatus::Published->value
                && $previousProfessorId !== $newProfessorId;
            if ($reassignedAfterPublish) {
                $this->notifyRegistrarHeads($actor, $section, $previousProfessorId, $newProfessorId);
                $this->notifyProfessors($section, $previousProfessorId, $newProfessorId);
            }

            $this->auditRecorder->record(
                $actor,
                $reassignedAfterPublish ? AuditAction::SECTION_PROFESSOR_REASSIGNED : AuditAction::SECTION_UPDATED,
                AuditableType::SECTION,
                $section->id,
                $beforeValues,
                self::snapshot($section),
                $validatedData['override_reason'] ?? null,
                $context,
            );

            // ADR 0043: a freshman who paid while every section was full is told when this
            // one opens — it has just been published, or a published one gained seats.
            $nowPublished = $section->status === SectionStatus::Published;
            $justOpened = $nowPublished
                && ($beforeValues['status'] !== SectionStatus::Published->value || $capacity > $beforeValues['capacity']);
            if ($justOpened) {
                $this->notifyAwaitingFreshmen->forSections([$section->id]);
            }

            return $section;
        });
    }

    /**
     * After publication the professors are told about the swap too: the new one gets the section on
     * their teaching schedule, and the one it was taken from should not find out by an empty roster.
     * (Before publication only a first assignment is announced, as above.)
     */
    private function notifyProfessors(Section $section, ?int $fromId, ?int $toId): void
    {
        $section->loadMissing('subject');
        $label = "{$section->subject->code} section {$section->section_code}";

        // A first assignment (no previous professor) is already announced by the caller.
        if ($toId !== null && $fromId !== null) {
            Notification::create([
                'user_id' => $toId,
                'type' => NotificationType::SectionAssigned,
                'message' => "You have been assigned to teach {$label}. Please check your teaching schedule.",
            ]);
        }

        if ($fromId !== null) {
            Notification::create([
                'user_id' => $fromId,
                'type' => NotificationType::SectionProfessorReassigned,
                'message' => "You no longer teach {$label}; it was assigned to another professor.",
            ]);
        }
    }

    private function notifyRegistrarHeads(User $actor, Section $section, ?int $fromId, ?int $toId): void
    {
        $names = User::query()->whereIn('id', array_filter([$fromId, $toId]))->pluck('name', 'id');
        $section->loadMissing('subject');

        User::query()
            ->where('role', UserRole::RegistrarHead)
            ->whereKeyNot($actor->id)
            ->get()
            ->each(fn (User $head) => Notification::create([
                'user_id' => $head->id,
                'type' => NotificationType::SectionProfessorReassigned,
                'message' => sprintf(
                    '%s changed the professor of %s section %s from %s to %s.',
                    $actor->name,
                    $section->subject->code,
                    $section->section_code,
                    $fromId === null ? 'no professor' : ($names[$fromId] ?? 'the previous professor'),
                    $toId === null ? 'no professor' : ($names[$toId] ?? 'a new professor'),
                ),
            ]));
    }

    /** @return array{academic_term_id: int, subject_id: int, section_code: string, professor_id: ?int, schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, room: ?string, modality: ?string, capacity: int, capacity_source: string, viability_threshold: ?int, enrolled_count: int, status: string} */
    private static function snapshot(Section $section): array
    {
        return [
            'academic_term_id' => $section->academic_term_id,
            'subject_id' => $section->subject_id,
            'section_code' => $section->section_code,
            'professor_id' => $section->professor_id,
            'schedule_days' => $section->schedule_days,
            'starts_at_time' => $section->starts_at_time,
            'ends_at_time' => $section->ends_at_time,
            'room' => $section->room,
            'modality' => $section->modality?->value,
            'capacity' => $section->capacity,
            'capacity_source' => $section->capacity_source->value,
            'viability_threshold' => $section->viability_threshold,
            'enrolled_count' => $section->enrolled_count,
            'status' => $section->status->value,
        ];
    }
}
