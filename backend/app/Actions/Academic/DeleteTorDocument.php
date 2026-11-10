<?php

namespace App\Actions\Academic;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\StudentTorDocument;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A student removes one of their own uploaded TOR files. The row goes in a
 * transaction with its audit entry; the file is removed once that has committed.
 * Removing a TOR does not undo any credit request already made from it.
 */
final readonly class DeleteTorDocument
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(User $actor, StudentTorDocument $document, AuditRequestContext $context): void
    {
        $path = $document->stored_path;

        DB::transaction(function () use ($actor, $document, $context): void {
            $before = ['student_id' => $document->student_id, 'mime_type' => $document->mime_type, 'size_bytes' => $document->size_bytes];
            $id = $document->id;
            $document->delete();

            $this->auditRecorder->record(
                $actor,
                AuditAction::TOR_DOCUMENT_DELETED,
                AuditableType::TOR_DOCUMENT,
                $id,
                $before,
                null,
                null,
                $context,
            );
        });

        Storage::disk('local')->delete($path);
    }
}
