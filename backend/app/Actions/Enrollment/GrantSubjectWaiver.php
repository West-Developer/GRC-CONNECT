<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\AcademicTerm;
use App\Models\CurriculumSubject;
use App\Models\EnrollmentSubjectWaiver;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lets one student take one subject in one term although a prerequisite is not
 * met (Registrar Head only; ADR 0031). The reason is required and lands in the
 * audit row; the waiver row keeps it too so the review screen can show why.
 *
 * Idempotent: granting an already-active waiver changes nothing and writes no
 * second audit row. Granting again after a revoke re-activates the same row.
 */
final readonly class GrantSubjectWaiver
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @return array{waiver: EnrollmentSubjectWaiver, changed: bool}
     */
    public function execute(
        StudentProfile $student,
        int $subjectId,
        AcademicTerm $term,
        string $reason,
        User $actor,
        AuditRequestContext $context,
    ): array {
        $inCurriculum = CurriculumSubject::query()
            ->where('curriculum_id', $student->curriculum_id)
            ->where('subject_id', $subjectId)
            ->exists();

        if (! $inCurriculum) {
            throw ValidationException::withMessages([
                'subject_id' => "That subject is not part of the student's curriculum.",
            ]);
        }

        return DB::transaction(function () use ($student, $subjectId, $term, $reason, $actor, $context): array {
            $waiver = EnrollmentSubjectWaiver::query()
                ->where('student_id', $student->id)
                ->where('subject_id', $subjectId)
                ->where('academic_term_id', $term->id)
                ->lockForUpdate()
                ->first();

            if ($waiver instanceof EnrollmentSubjectWaiver && $waiver->revoked_at === null) {
                return ['waiver' => $waiver->load('subject'), 'changed' => false];
            }

            $before = $waiver instanceof EnrollmentSubjectWaiver ? self::snapshot($waiver) : null;

            $attributes = [
                'reason' => $reason,
                'granted_by' => $actor->id,
                'granted_at' => now(),
                'revoked_by' => null,
                'revoked_at' => null,
            ];

            if ($waiver instanceof EnrollmentSubjectWaiver) {
                $waiver->update($attributes);
            } else {
                $waiver = EnrollmentSubjectWaiver::create([
                    'student_id' => $student->id,
                    'subject_id' => $subjectId,
                    'academic_term_id' => $term->id,
                    ...$attributes,
                ]);
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::ENROLLMENT_SUBJECT_WAIVER_GRANTED,
                AuditableType::ENROLLMENT_SUBJECT_WAIVER,
                $waiver->id,
                $before,
                self::snapshot($waiver->refresh()),
                $reason,
                $context,
            );

            return ['waiver' => $waiver->load('subject'), 'changed' => true];
        });
    }

    /**
     * @return array{student_id: int, subject_id: int, academic_term_id: int, active: bool}
     */
    public static function snapshot(EnrollmentSubjectWaiver $waiver): array
    {
        return [
            'student_id' => $waiver->student_id,
            'subject_id' => $waiver->subject_id,
            'academic_term_id' => $waiver->academic_term_id,
            'active' => $waiver->revoked_at === null,
        ];
    }
}
