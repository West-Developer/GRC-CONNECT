<?php

namespace App\Actions\ItControl;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes a Student or Faculty account that was never used (ADR 0054). "Used" is decided by what
 * points at the account: every table with a foreign key to the user (or to the student's profile) that is not on
 * a short list of the account's own plumbing must hold no row for it. A table added later therefore blocks the
 * delete by default, and nothing that history depends on is ever removed or silently left without its owner
 * (a section keeps its professor, a payment its student). An account with records is archived instead.
 */
final class DeleteUnusedManagedAccount
{
    /**
     * Rows that belong to the account itself and go with it: sign-in and setup codes, its own notices, a
     * faculty member's own availability, preferences and specialization requests, and the student profile
     * (checked separately below).
     *
     * @var list<string>
     */
    private const USER_OWNED_TABLES = [
        'student_profiles',
        'account_setup_codes',
        'password_reset_codes',
        'login_otp_challenges',
        'notifications',
        'faculty_availabilities',
        'faculty_curriculum_subject_preferences',
        'faculty_subject_preferences',
        'faculty_specializations',
        'faculty_load_overrides',
        // The person's own record of having read the Data Privacy Notice (ADR 0055).
        'privacy_notice_acceptances',
    ];

    /**
     * Rows that belong to a student's profile and go with it: the freshman queue number, the admission
     * checklist, change requests and schedule preferences.
     *
     * @var list<string>
     */
    private const PROFILE_OWNED_TABLES = [
        'queue_tickets',
        'student_admission_requirements',
        'student_profile_change_requests',
        'student_schedule_preferences',
    ];

    private const HAS_RECORDS = 'This account has records, so it cannot be deleted. Archive it instead.';

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function execute(User $target, User $actor, AuditRequestContext $context): void
    {
        DB::transaction(function () use ($target, $actor, $context): void {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $profile = StudentProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();

            $this->refuseWhenUsed('users', $locked->id, self::USER_OWNED_TABLES);
            if ($profile !== null) {
                $this->refuseWhenUsed('student_profiles', $profile->id, self::PROFILE_OWNED_TABLES);
            }

            $before = ['role' => $locked->role->value, 'college' => $locked->college?->value];

            try {
                $profile?->delete();
                $locked->tokens()->delete();
                $locked->delete();
            } catch (QueryException $exception) {
                // A row appeared between the check and the delete: the database refuses, and so do we.
                if ($exception->getCode() === '23000' || str_contains($exception->getMessage(), '23000')) {
                    abort(409, self::HAS_RECORDS);
                }

                throw $exception;
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

    /**
     * @param  list<string>  $ownedTables
     */
    private function refuseWhenUsed(string $referencedTable, int $id, array $ownedTables): void
    {
        $foreignKeys = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?',
            [DB::getDatabaseName(), $referencedTable, 'id'],
        );

        foreach ($foreignKeys as $foreignKey) {
            $table = (string) $foreignKey->TABLE_NAME;
            if (in_array($table, $ownedTables, true)) {
                continue;
            }

            if (DB::table($table)->where((string) $foreignKey->COLUMN_NAME, $id)->exists()) {
                abort(409, self::HAS_RECORDS);
            }
        }
    }
}
