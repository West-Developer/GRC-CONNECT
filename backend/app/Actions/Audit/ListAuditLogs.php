<?php

namespace App\Actions\Audit;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditChangeFormatter;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final readonly class ListAuditLogs
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
        $actorUserId = isset($filters['actor_user_id']) ? (int) $filters['actor_user_id'] : null;
        $from = isset($filters['from']) ? (string) $filters['from'] : null;
        $to = isset($filters['to']) ? (string) $filters['to'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return DB::transaction(function () use (
            $actor,
            $action,
            $auditableType,
            $actorUserId,
            $from,
            $to,
            $page,
            $perPage,
            $context,
        ): LengthAwarePaginator {
            $query = AuditLog::query()
                ->with('actor')
                ->when($action !== null, fn ($query) => $query->where('action', $action))
                ->when($auditableType !== null, fn ($query) => $query->where('auditable_type', $auditableType))
                ->when($actorUserId !== null, fn ($query) => $query->where('actor_user_id', $actorUserId))
                ->when(
                    $from !== null,
                    fn ($query) => $query->where(
                        'created_at',
                        '>=',
                        CarbonImmutable::parse($from, 'UTC')->startOfDay(),
                    ),
                )
                ->when(
                    $to !== null,
                    fn ($query) => $query->where(
                        'created_at',
                        '<=',
                        CarbonImmutable::parse($to, 'UTC')->endOfDay(),
                    ),
                )
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $paginator = $query
                ->paginate($perPage, ['*'], 'page', $page)
                ->withQueryString();

            // One lookup for every professor/decider named on this page, so the
            // change list can say who rather than "User #7".
            $userNames = AuditChangeFormatter::userNamesFor($paginator->items());
            foreach ($paginator->items() as $log) {
                $log->setAttribute('user_names', $userNames);
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::AUDIT_LOG_LIST_VIEWED,
                AuditableType::AUDIT_LOG,
                null,
                null,
                [
                    'action' => $action,
                    'auditable_type' => $auditableType,
                    'actor_user_id' => $actorUserId,
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
