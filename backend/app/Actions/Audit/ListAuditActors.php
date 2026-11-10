<?php

namespace App\Actions\Audit;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The audit screen's first level: who did something, how much, and when they
 * last did (stakeholder Doc 14 "one accordion per user"). Same filters as the
 * entry list minus the actor; the entries themselves are then read per actor
 * through `ListAuditLogs`. Registrar Head only, and, like the entry list,
 * viewing it is itself audited.
 */
final readonly class ListAuditActors
{
    public function __construct(
        private AuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function execute(
        User $actor,
        array $filters,
        AuditRequestContext $context,
    ): LengthAwarePaginator {
        $action = isset($filters['action']) ? (string) $filters['action'] : null;
        $auditableType = isset($filters['auditable_type']) ? (string) $filters['auditable_type'] : null;
        $from = isset($filters['from']) ? (string) $filters['from'] : null;
        $to = isset($filters['to']) ? (string) $filters['to'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return DB::transaction(function () use ($actor, $action, $auditableType, $from, $to, $page, $perPage, $context): LengthAwarePaginator {
            $paginator = AuditLog::query()
                ->with('actor')
                ->select('actor_user_id')
                ->selectRaw('count(*) as entries_count')
                ->selectRaw('max(created_at) as last_activity_at')
                ->when($action !== null, fn ($query) => $query->where('action', $action))
                ->when($auditableType !== null, fn ($query) => $query->where('auditable_type', $auditableType))
                ->when($from !== null, fn ($query) => $query->where('created_at', '>=', CarbonImmutable::parse($from, 'UTC')->startOfDay()))
                ->when($to !== null, fn ($query) => $query->where('created_at', '<=', CarbonImmutable::parse($to, 'UTC')->endOfDay()))
                ->groupBy('actor_user_id')
                ->orderByDesc('last_activity_at')
                ->orderBy('actor_user_id')
                ->paginate($perPage, ['actor_user_id'], 'page', $page)
                ->withQueryString();

            $this->auditRecorder->record(
                $actor,
                AuditAction::AUDIT_LOG_LIST_VIEWED,
                AuditableType::AUDIT_LOG,
                null,
                null,
                [
                    'group_by' => 'actor',
                    'action' => $action,
                    'auditable_type' => $auditableType,
                    'from' => $from,
                    'to' => $to,
                    'page' => $page,
                    'per_page' => $perPage,
                ],
                null,
                $context,
            );

            return $paginator;
        });
    }
}
