<?php

namespace App\Actions\Registrar;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\AcademicTerm;
use App\Models\ProgramShift;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Registrar records that a student shifted from their current course to
 * another for a term (stakeholder Doc 14, ADR 0034). The "from" course is the
 * student's course at the moment of recording. Recording changes nothing else:
 * not the student's program, not their curriculum. Recording the same shift
 * again for the same student and term returns the existing record and writes
 * no second audit row.
 */
final readonly class RecordProgramShift
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @return array{shift: ProgramShift, created: bool}
     */
    public function execute(
        User $actor,
        StudentProfile $student,
        int $toProgramId,
        AcademicTerm $term,
        string $reason,
        AuditRequestContext $context,
    ): array {
        if ($student->is_demo_account) {
            throw ValidationException::withMessages(['student_number' => 'A demo account cannot have a course shift.']);
        }

        if ($student->program_id === $toProgramId) {
            throw ValidationException::withMessages(['to_program_id' => 'The student is already in that course.']);
        }

        return DB::transaction(function () use ($actor, $student, $toProgramId, $term, $reason, $context): array {
            $existing = ProgramShift::query()
                ->where('student_id', $student->id)
                ->where('academic_term_id', $term->id)
                ->where('from_program_id', $student->program_id)
                ->where('to_program_id', $toProgramId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ProgramShift) {
                return ['shift' => $existing, 'created' => false];
            }

            $shift = ProgramShift::create([
                'student_id' => $student->id,
                'from_program_id' => $student->program_id,
                'to_program_id' => $toProgramId,
                'academic_term_id' => $term->id,
                'reason' => $reason,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::PROGRAM_SHIFT_RECORDED,
                AuditableType::PROGRAM_SHIFT,
                $shift->id,
                ['program_id' => $shift->from_program_id],
                ['program_id' => $shift->to_program_id, 'academic_term_id' => $term->id],
                $reason,
                $context,
            );

            return ['shift' => $shift, 'created' => true];
        });
    }
}
