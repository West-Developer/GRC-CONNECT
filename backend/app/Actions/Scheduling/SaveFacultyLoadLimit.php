<?php

namespace App\Actions\Scheduling;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\FacultyEmploymentType;
use App\Models\AcademicTerm;
use App\Models\FacultyLoadLimit;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Sets the maximum teaching units for one employment type in the acting user's
 * own college and a term (ADR 0033). Saving the value already in place changes
 * nothing and writes no audit row.
 */
final readonly class SaveFacultyLoadLimit
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(
        User $actor,
        AcademicTerm $term,
        FacultyEmploymentType $employmentType,
        float $maxUnits,
        AuditRequestContext $context,
    ): FacultyLoadLimit {
        $college = $actor->college?->value;
        abort_if($college === null, 422, 'A college-scoped Program Head or Dean is required.');

        return DB::transaction(function () use ($actor, $term, $employmentType, $maxUnits, $college, $context): FacultyLoadLimit {
            $existing = FacultyLoadLimit::query()
                ->where('academic_term_id', $term->id)
                ->where('college', $college)
                ->where('employment_type', $employmentType->value)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof FacultyLoadLimit && abs($existing->max_units - $maxUnits) < 0.005) {
                return $existing;
            }

            $limit = FacultyLoadLimit::updateOrCreate(
                ['academic_term_id' => $term->id, 'college' => $college, 'employment_type' => $employmentType->value],
                ['max_units' => $maxUnits, 'configured_by' => $actor->id],
            );

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_LOAD_LIMIT_UPDATED,
                AuditableType::FACULTY_LOAD_LIMIT,
                $limit->id,
                $existing instanceof FacultyLoadLimit
                    ? ['employment_type' => $employmentType->value, 'max_units' => $existing->max_units]
                    : null,
                ['employment_type' => $employmentType->value, 'max_units' => $limit->max_units],
                null,
                $context,
            );

            return $limit;
        });
    }
}
