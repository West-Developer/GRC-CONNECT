<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\AcademicGrade;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * DFD 3.1 "Encode Grade": creates one draft grade record for a student in
 * one of the encoding professor's own sections (FR set by PRD §4.3/§5.3).
 * `StoreAcademicGradeRequest` has already re-validated section ownership and
 * the (student, subject, term) uniqueness before this executes.
 */
final readonly class RecordAcademicGrade
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(array $validated, User $actor, AuditRequestContext $context): AcademicGrade
    {
        return DB::transaction(function () use ($validated, $actor, $context): AcademicGrade {
            $mark = isset($validated['mark']) ? GradeMark::from($validated['mark']) : null;

            $grade = AcademicGrade::create([
                'student_id' => $validated['student_id'],
                'subject_id' => $validated['subject_id'],
                'section_id' => $validated['section_id'],
                'academic_term_id' => $validated['academic_term_id'],
                'mark' => $mark,
                'final_grade' => $mark?->numericValue(),
                'remarks' => $validated['remarks'] ?? null,
                'status' => GradeStatus::Draft,
                'encoded_by' => $actor->id,
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::ACADEMIC_GRADE_CREATED,
                AuditableType::ACADEMIC_GRADE,
                $grade->id,
                null,
                [
                    'student_id' => $grade->student_id,
                    'subject_id' => $grade->subject_id,
                    'section_id' => $grade->section_id,
                    'academic_term_id' => $grade->academic_term_id,
                    'mark' => $grade->mark?->value,
                    'status' => $grade->status->value,
                ],
                null,
                $context,
            );

            return $grade->refresh()->load(['student', 'subject', 'section']);
        });
    }
}
