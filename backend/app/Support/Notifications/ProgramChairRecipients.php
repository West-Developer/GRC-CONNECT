<?php

namespace App\Support\Notifications;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Program Chairs a notice about one student goes to: the active chairs of
 * the student's college, and any chair without an assigned college (who is
 * unscoped, the same fallback `TransfereeCredit::scopeVisibleTo` uses).
 */
final class ProgramChairRecipients
{
    /**
     * @return list<int>
     */
    public static function forStudent(StudentProfile $student): array
    {
        $college = $student->program->college;

        return array_values(User::query()
            ->where('role', UserRole::ProgramChair->value)
            ->where('status', UserStatus::Active->value)
            ->where(function (Builder $query) use ($college): void {
                $query->whereNull('college');

                if ($college !== null) {
                    $query->orWhere('college', $college->value);
                }
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }
}
