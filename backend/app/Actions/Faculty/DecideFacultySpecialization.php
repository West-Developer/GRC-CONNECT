<?php

namespace App\Actions\Faculty;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Faculty\FacultySpecializationStatus;
use App\Domain\Notifications\NotificationType;
use App\Models\FacultySpecialization;
use App\Models\Notification;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class DecideFacultySpecialization
{
    /** @var array<string, FacultySpecializationStatus> */
    private const TARGET_STATUS = [
        'approve' => FacultySpecializationStatus::Approved,
        'reject' => FacultySpecializationStatus::Rejected,
    ];

    /** @var array<string, string> */
    private const AUDIT_ACTION = [
        'approve' => AuditAction::FACULTY_SPECIALIZATION_APPROVED,
        'reject' => AuditAction::FACULTY_SPECIALIZATION_REJECTED,
    ];

    /** @var array<string, NotificationType> */
    private const NOTIFICATION_TYPE = [
        'approve' => NotificationType::FacultySpecializationApproved,
        'reject' => NotificationType::FacultySpecializationRejected,
    ];

    private const REASON_REQUIRED_ACTIONS = ['reject'];

    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(
        FacultySpecialization $specialization,
        string $action,
        User $actor,
        ?string $reason,
        AuditRequestContext $context,
    ): FacultySpecialization {
        if (! isset(self::TARGET_STATUS[$action])) {
            throw new InvalidArgumentException('Unknown specialization decision.');
        }

        if (
            in_array($action, self::REASON_REQUIRED_ACTIONS, true)
            && ($reason === null || trim($reason) === '')
        ) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required for this action.',
            ]);
        }

        return DB::transaction(function () use ($specialization, $action, $actor, $reason, $context): FacultySpecialization {
            $locked = FacultySpecialization::query()
                ->whereKey($specialization->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== FacultySpecializationStatus::Pending) {
                throw ValidationException::withMessages([
                    'action' => 'This action requires the specialization to currently be '.
                        "'pending'; it is currently '{$locked->status->value}'.",
                ]);
            }

            $beforeValues = CreateFacultySpecialization::snapshot($locked);

            $locked->update([
                'status' => self::TARGET_STATUS[$action],
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => $action === 'reject' ? $reason : null,
            ]);
            $locked->refresh();

            $this->auditRecorder->record(
                $actor,
                self::AUDIT_ACTION[$action],
                AuditableType::FACULTY_SPECIALIZATION,
                $locked->id,
                $beforeValues,
                CreateFacultySpecialization::snapshot($locked),
                $action === 'reject' ? $reason : null,
                $context,
            );

            Notification::create([
                'user_id' => $locked->professor_id,
                'type' => self::NOTIFICATION_TYPE[$action],
                'message' => self::notificationMessage($action, $reason),
            ]);

            return $locked;
        });
    }

    private static function notificationMessage(string $action, ?string $reason): string
    {
        return match ($action) {
            'approve' => 'A subject you can teach has been approved by your Program Head.',
            'reject' => "A subject you declared was not approved by your Program Head. Reason: {$reason}",
            default => 'Your declared subject status has changed.',
        };
    }
}
