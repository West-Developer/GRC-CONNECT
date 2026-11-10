<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\EnrollmentSubjectWaiver;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Takes a waiver back (Registrar Head only). The subject stops being
 * eligible for that prerequisite reason again; a student who already
 * submitted an enrollment with it keeps that enrollment, which the Registrar
 * can still reject or void.
 *
 * Idempotent: revoking an already-revoked waiver changes nothing and writes no
 * second audit row.
 */
final readonly class RevokeSubjectWaiver
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(EnrollmentSubjectWaiver $waiver, User $actor, AuditRequestContext $context): EnrollmentSubjectWaiver
    {
        return DB::transaction(function () use ($waiver, $actor, $context): EnrollmentSubjectWaiver {
            $locked = EnrollmentSubjectWaiver::query()->whereKey($waiver->id)->lockForUpdate()->firstOrFail();

            if ($locked->revoked_at !== null) {
                return $locked->load('subject');
            }

            $before = GrantSubjectWaiver::snapshot($locked);
            $locked->update(['revoked_by' => $actor->id, 'revoked_at' => now()]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::ENROLLMENT_SUBJECT_WAIVER_REVOKED,
                AuditableType::ENROLLMENT_SUBJECT_WAIVER,
                $locked->id,
                $before,
                GrantSubjectWaiver::snapshot($locked->refresh()),
                null,
                $context,
            );

            return $locked->load('subject');
        });
    }
}
