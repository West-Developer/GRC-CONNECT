<?php

namespace App\Actions\SuperAdmin;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditRequestContext;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteUnusedUserAccount
{
    /** @var list<string> */
    private const EXCLUDED_FK_TABLES = [
        'account_setup_codes',
        'password_reset_codes',
        'login_otp_challenges',
    ];

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function execute(
        User $target,
        User $actor,
        AuditRequestContext $context,
    ): void {
        DB::transaction(function () use ($target, $actor, $context): void {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($locked->account_setup_completed_at !== null) {
                abort(409, 'Deactivate instead — this account has history.');
            }

            $databaseName = DB::getDatabaseName();
            $foreignKeys = DB::select("
                SELECT TABLE_NAME, COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE REFERENCED_TABLE_SCHEMA = ?
                  AND REFERENCED_TABLE_NAME = 'users'
                  AND REFERENCED_COLUMN_NAME = 'id'
            ", [$databaseName]);

            foreach ($foreignKeys as $fk) {
                $tableName = $fk->TABLE_NAME;
                $columnName = $fk->COLUMN_NAME;

                if (in_array($tableName, self::EXCLUDED_FK_TABLES, true)) {
                    continue;
                }

                $hasHistory = DB::table($tableName)->where($columnName, $locked->id)->exists();
                if ($hasHistory) {
                    abort(409, 'Deactivate instead — this account has history.');
                }
            }

            $before = [
                'role' => $locked->role->value,
                'college' => $locked->college?->value,
                'name' => $locked->name,
            ];

            $locked->tokens()->delete();

            try {
                $locked->delete();
            } catch (QueryException $e) {
                if ($e->getCode() === '23000' || str_contains($e->getMessage(), '23000')) {
                    abort(409, 'Deactivate instead — this account has history.');
                }
                throw $e;
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::USER_ACCOUNT_DELETED,
                AuditableType::USER_ACCOUNT,
                $target->id,
                $before,
                ['deleted' => true],
                null,
                $context,
            );
        });
    }
}
