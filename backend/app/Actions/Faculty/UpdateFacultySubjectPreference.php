<?php

namespace App\Actions\Faculty;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\FacultySubjectPreference;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class UpdateFacultySubjectPreference
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /** @param array{academic_term_id: int, subject_id: int, rank: int} $validatedData */
    public function execute(
        User $actor,
        array $validatedData,
        FacultySubjectPreference $preference,
        AuditRequestContext $context,
    ): FacultySubjectPreference {
        return DB::transaction(function () use ($actor, $validatedData, $preference, $context): FacultySubjectPreference {
            $beforeValues = self::snapshot($preference);

            $preference->update([
                'academic_term_id' => $validatedData['academic_term_id'],
                'subject_id' => $validatedData['subject_id'],
                'rank' => $validatedData['rank'],
            ]);
            $preference->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_SUBJECT_PREFERENCE_UPDATED,
                AuditableType::FACULTY_SUBJECT_PREFERENCE,
                $preference->id,
                $beforeValues,
                self::snapshot($preference),
                null,
                $context,
            );

            return $preference;
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
