<?php

namespace App\Actions\Scheduling;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\AcademicTerm;
use App\Models\FacultyLoadOverride;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Takes a professor's own maximum away so the normal limit applies again
 * (ADR 0033). Removing one that is not there changes nothing and writes no
 * audit row.
 */
final readonly class ClearFacultyLoadOverride
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @return bool whether an override existed and was removed */
    public function execute(User $actor, AcademicTerm $term, User $professor, AuditRequestContext $context): bool
    {
        SaveFacultyLoadOverride::assertProfessorInCollege($actor, $professor);

        return DB::transaction(function () use ($actor, $term, $professor, $context): bool {
            $existing = FacultyLoadOverride::query()
                ->where('academic_term_id', $term->id)
                ->where('professor_id', $professor->id)
                ->lockForUpdate()
                ->first();

            if (! $existing instanceof FacultyLoadOverride) {
                return false;
            }

            $existing->delete();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_LOAD_OVERRIDE_CLEARED,
                AuditableType::FACULTY_LOAD_OVERRIDE,
                $existing->id,
                ['professor_id' => $professor->id, 'max_units' => $existing->max_units],
                null,
                null,
                $context,
            );

            return true;
        });
    }
}
