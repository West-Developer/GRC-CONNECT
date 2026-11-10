<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class ListFacultyMembers
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @return Collection<int, User>
     */
    public function execute(
        User $actor,
        AuditRequestContext $context,
        bool $includeInactive = false,
        ?string $college = null,
    ): Collection {
        return DB::transaction(function () use ($actor, $context, $includeInactive, $college): Collection {
            $members = User::query()
                ->where('role', UserRole::Faculty)
                ->when(
                    $actor->role === UserRole::ProgramChair,
                    fn ($query) => $query->where('college', $actor->college?->value),
                )
                ->when(
                    $actor->role === UserRole::Faculty,
                    fn ($query) => $query->where('id', $actor->id),
                )
                ->when(
                    $actor->role === UserRole::RegistrarHead && $college !== null,
                    fn ($query) => $query->where('college', $college),
                )
                ->when(! $includeInactive, fn ($query) => $query->where('status', UserStatus::Active))
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'college', 'employment_type', 'status']);

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_DIRECTORY_LIST_VIEWED,
                AuditableType::FACULTY_DIRECTORY,
                null,
                null,
                ['result_count' => $members->count(), 'include_inactive' => $includeInactive],
                null,
                $context,
            );

            return $members;
        });
    }
}
