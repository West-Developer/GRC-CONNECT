<?php

namespace App\Support\Notifications;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Batched notification writer, mirroring the shape of
 * `App\Support\Audit\AuditRecorder` for the schedule-proposal notification
 * fan-out. Every existing call site (`Notification::create([...])` inline in
 * an Action) is left as-is; this is used only by new code so the two
 * patterns can coexist without a repo-wide refactor.
 */
final class NotificationRecorder
{
    /**
     * @param  list<int>  $recipientUserIds
     */
    public function recordMany(array $recipientUserIds, NotificationType $type, string $message): void
    {
        $uniqueRecipientIds = array_values(array_unique($recipientUserIds));

        if ($uniqueRecipientIds === []) {
            return;
        }

        sort($uniqueRecipientIds);
        $now = Carbon::now();

        $rows = array_map(
            static fn (int $userId): array => [
                'user_id' => $userId,
                'type' => $type->value,
                'message' => $message,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $uniqueRecipientIds,
        );

        Notification::query()->insert($rows);
    }

    /**
     * Fan out to every active user of a single role — the shape
     * `SubmitEnrollment` (Registrar Staff), `TransitionEnrollment`'s
     * `registrar_approve` (Accounting Staff), and `RequestEnrollmentChange`
     * (Registrar Head) all need: nobody is scoped to a college or section
     * the way `NotifyScheduleTransition` handles, so a plain active-role
     * broadcast is correct, not a simplification.
     */
    public function recordManyForRole(UserRole $role, NotificationType $type, string $message): void
    {
        $this->recordManyForRoles([$role], $type, $message);
    }

    /**
     * Same broadcast for several roles at once (e.g. Registrar Staff and Registrar Head, who both
     * decide the Registrar stage), each active user notified once.
     *
     * @param  list<UserRole>  $roles
     */
    public function recordManyForRoles(array $roles, NotificationType $type, string $message): void
    {
        $recipientIds = User::query()
            ->whereIn('role', array_map(static fn (UserRole $role): string => $role->value, $roles))
            ->where('status', UserStatus::Active->value)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->recordMany(array_values($recipientIds), $type, $message);
    }
}
