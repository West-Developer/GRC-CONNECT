<?php

namespace App\Actions\Faculty;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\FacultySubjectPreference;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class DeleteFacultySubjectPreference
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function execute(User $actor, FacultySubjectPreference $preference, AuditRequestContext $context): void
    {
        DB::transaction(function () use ($actor, $preference, $context): void {
            $beforeValues = self::snapshot($preference);
            $preference->delete();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_SUBJECT_PREFERENCE_DELETED,
                AuditableType::FACULTY_SUBJECT_PREFERENCE,
                $preference->id,
                $beforeValues,
                null,
                null,
                $context,
            );
        });
    }

    /** @return array{professor_id: int, academic_term_id: int, subject_id: int, rank: int} */
    private static function snapshot(FacultySubjectPreference $preference): array
    {
        return [
            'professor_id' => $preference->professor_id,
            'academic_term_id' => $preference->academic_term_id,
            'subject_id' => $preference->subject_id,
            'rank' => $preference->rank,
        ];
    }
}
