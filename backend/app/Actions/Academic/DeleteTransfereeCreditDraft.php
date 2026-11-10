<?php

namespace App\Actions\Academic;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\TransfereeCredit;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A Student removes a subject from their own saved list (ADR 0026, amended).
 * Only a `draft` can go: once the list is sent the row is part of the workflow
 * and a record other people have acted on, so it is never deleted. Whose row it
 * is is `TransfereeCreditPolicy::deleteDraft`; the status is re-checked here
 * under the row lock. The removal is audited (before-values only, no content of
 * a transcript beyond the row's own fields).
 */
final readonly class DeleteTransfereeCreditDraft
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(TransfereeCredit $credit, User $actor, AuditRequestContext $context): void
    {
        DB::transaction(function () use ($credit, $actor, $context): void {
            $locked = TransfereeCredit::query()->whereKey($credit->id)->lockForUpdate()->first();

            // Gone already (a double tap that raced this one): nothing left to do.
            if ($locked === null) {
                return;
            }

            if ($locked->status !== TransfereeCreditStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => "Only a draft can be removed; this credit is '{$locked->status->value}'.",
                ]);
            }

            $before = [
                'status' => $locked->status->value,
                'credited_units' => $locked->credited_units,
                'subject_id' => $locked->subject_id,
            ];

            $locked->delete();

            $this->auditRecorder->record(
                $actor,
                AuditAction::TRANSFEREE_CREDIT_DELETED,
                AuditableType::TRANSFEREE_CREDIT,
                $locked->id,
                $before,
                null,
                null,
                $context,
            );
        });
    }
}
