<?php

namespace App\Actions\Scheduling;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Models\AcademicTerm;
use App\Models\FacultyLoadOverride;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives one professor their own maximum teaching units for a term, above or
 * below the normal limit (ADR 0033). The Program Head or Dean sets it, for a
 * professor of their own college, with a reason. Saving what is already in
 * place changes nothing and writes no audit row.
 */
final readonly class SaveFacultyLoadOverride
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(
        User $actor,
        AcademicTerm $term,
        User $professor,
        float $maxUnits,
        string $reason,
        AuditRequestContext $context,
    ): FacultyLoadOverride {
        self::assertProfessorInCollege($actor, $professor);

        return DB::transaction(function () use ($actor, $term, $professor, $maxUnits, $reason, $context): FacultyLoadOverride {
            $existing = FacultyLoadOverride::query()
                ->where('academic_term_id', $term->id)
                ->where('professor_id', $professor->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof FacultyLoadOverride && abs($existing->max_units - $maxUnits) < 0.005 && $existing->reason === $reason) {
                return $existing;
            }

            $override = FacultyLoadOverride::updateOrCreate(
                ['academic_term_id' => $term->id, 'professor_id' => $professor->id],
                ['max_units' => $maxUnits, 'reason' => $reason, 'set_by' => $actor->id],
            );

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_LOAD_OVERRIDE_SET,
                AuditableType::FACULTY_LOAD_OVERRIDE,
                $override->id,
                $existing instanceof FacultyLoadOverride
                    ? ['professor_id' => $professor->id, 'max_units' => $existing->max_units]
                    : null,
                ['professor_id' => $professor->id, 'max_units' => $override->max_units],
                $reason,
                $context,
            );

            return $override;
        });
    }

    /**
     * A Program Head or Dean only ever handles professors of their own college.
     * A professor with no college on file is allowed, since some legacy faculty
     * accounts were never given one.
     */
    public static function assertProfessorInCollege(User $actor, User $professor): void
    {
        if ($professor->role !== UserRole::Faculty) {
            throw ValidationException::withMessages(['professor_id' => 'Choose a professor.']);
        }

        if ($professor->college !== null && $professor->college !== $actor->college) {
            throw ValidationException::withMessages(['professor_id' => 'That professor belongs to another college.']);
        }
    }
}
