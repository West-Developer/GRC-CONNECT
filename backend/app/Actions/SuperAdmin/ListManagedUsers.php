<?php

namespace App\Actions\SuperAdmin;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListManagedUsers
{
    public function __construct(
        private AuditRecorder $auditRecorder,
    ) {}

    public function execute(
        User $actor,
        AuditRequestContext $context,
        ?string $search = null,
        ?UserRole $role = null,
        ?UserStatus $status = null,
        ?CollegeCode $college = null,
        ?bool $pendingSetup = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = User::query()
            ->withCount('tokens')
            ->when($search !== null && trim($search) !== '', function (Builder $q) use ($search): void {
                $term = '%'.trim($search).'%';
                $q->where(function (Builder $sub) use ($term): void {
                    $sub->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when($role !== null, fn (Builder $q) => $q->where('role', $role->value))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status->value))
            ->when($college !== null, fn (Builder $q) => $q->where('college', $college->value))
            ->when($pendingSetup !== null, function (Builder $q) use ($pendingSetup): void {
                if ($pendingSetup) {
                    $q->whereNull('account_setup_completed_at');
                } else {
                    $q->whereNotNull('account_setup_completed_at');
                }
            })
            ->orderBy('id', 'desc');

        $users = $query->paginate($perPage);

        $this->auditRecorder->record(
            $actor,
            AuditAction::USER_ACCOUNT_LIST_VIEWED,
            AuditableType::USER_ACCOUNT,
            null,
            null,
            [
                'count' => $users->total(),
                'has_search' => $search !== null && trim($search) !== '',
            ],
            null,
            $context,
        );

        return $users;
    }
}
