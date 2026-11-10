<?php

namespace App\Actions\Privacy;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\PrivacyNoticeAcceptance;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Privacy\PrivacyNotice;
use Illuminate\Support\Facades\DB;

/**
 * Remembers that a person agreed to the current Data Privacy Notice, or that Admission showed it to an applicant
 * (ADR 0055). Idempotent: the same person, version and method is one row, kept with its first time, and is
 * audited once.
 */
final class RecordPrivacyNoticeAcceptance
{
    public function __construct(
        private readonly PrivacyNotice $notice,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /** The person read the notice in the portal and agreed. */
    public function agree(User $user, AuditRequestContext $context): PrivacyNoticeAcceptance
    {
        return DB::transaction(function () use ($user, $context): PrivacyNoticeAcceptance {
            $acceptance = PrivacyNoticeAcceptance::query()->createOrFirst(
                [
                    'user_id' => $user->id,
                    'notice_version' => $this->notice->version(),
                    'method' => PrivacyNoticeAcceptance::METHOD_SELF,
                ],
                ['accepted_at' => now()],
            );

            if ($acceptance->wasRecentlyCreated) {
                $this->auditRecorder->record(
                    $user,
                    AuditAction::PRIVACY_NOTICE_ACCEPTED,
                    AuditableType::PRIVACY_NOTICE_ACCEPTANCE,
                    $acceptance->id,
                    null,
                    ['notice_version' => $acceptance->notice_version, 'method' => $acceptance->method],
                    null,
                    $context,
                );
            }

            return $acceptance;
        });
    }

    /** Admission confirmed the applicant was shown the notice when the account was created. */
    public function recordShownByStaff(User $student, User $staff, AuditRequestContext $context): PrivacyNoticeAcceptance
    {
        return DB::transaction(function () use ($student, $staff, $context): PrivacyNoticeAcceptance {
            $acceptance = PrivacyNoticeAcceptance::query()->createOrFirst(
                [
                    'user_id' => $student->id,
                    'notice_version' => $this->notice->version(),
                    'method' => PrivacyNoticeAcceptance::METHOD_ASSISTED,
                ],
                ['recorded_by_user_id' => $staff->id, 'accepted_at' => now()],
            );

            if ($acceptance->wasRecentlyCreated) {
                $this->auditRecorder->record(
                    $staff,
                    AuditAction::PRIVACY_NOTICE_ACKNOWLEDGED_FOR_STUDENT,
                    AuditableType::PRIVACY_NOTICE_ACCEPTANCE,
                    $acceptance->id,
                    null,
                    [
                        'user_id' => $student->id,
                        'notice_version' => $acceptance->notice_version,
                        'method' => $acceptance->method,
                    ],
                    null,
                    $context,
                );
            }

            return $acceptance;
        });
    }
}
