<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Notification;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One route serves two concerns, mirroring ADR 0011's shape for the status
 * transitions while adding a third path this table needs that
 * `TransitionEnrollment`/`TransitionScheduleProposal` don't: a plain content
 * edit with no `action` at all.
 *
 *   - No `action`: the encoding Faculty member corrects `mark` or
 *     `remarks` — allowed only while the grade is still `draft`
 *     (`GradeStatus::isEditableByEncoder()`).
 *   - `action: submit` (`draft` → `submitted`, Faculty) or
 *     `action: lock` (`submitted` → `locked`, Registrar Head): a pure
 *     status transition, re-checked here under a row lock as defense in
 *     depth even though `UpdateAcademicGradeRequest` already checked it.
 *
 * `lock` is the one moment a grade becomes part of the official record that
 * `BuildEligibleSubjectPool` reads for prerequisite evaluation, so it is
 * also the one moment worth notifying the student about — matching why
 * `SubmitEnrollment`/`TransitionEnrollment`/`TransitionScheduleProposal::publish`
 * notify but plainer writes elsewhere in this codebase don't.
 */
final readonly class UpdateAcademicGrade
{
    /**
     * @var array<string, GradeStatus>
     */
    private const TARGET_STATUS = [
        'submit' => GradeStatus::Submitted,
        'lock' => GradeStatus::Locked,
    ];

    /**
     * @var array<string, GradeStatus>
     */
    private const REQUIRED_CURRENT_STATUS = [
        'submit' => GradeStatus::Draft,
        'lock' => GradeStatus::Submitted,
    ];

    /**
     * @var array<string, string>
     */
    private const AUDIT_ACTION = [
        'submit' => AuditAction::ACADEMIC_GRADE_SUBMITTED,
        'lock' => AuditAction::ACADEMIC_GRADE_LOCKED,
    ];

    public function __construct(
        private AuditRecorder $auditRecorder,
        private ReclassifyStudentEnrollmentCategory $reclassifier,
        private PromoteEligibleStudents $promoter,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(AcademicGrade $grade, array $validated, User $actor, AuditRequestContext $context): AcademicGrade
    {
        $action = $validated['action'] ?? null;

        if (is_string($action) && isset(self::TARGET_STATUS[$action])) {
            return $this->transition($grade, $action, $actor, $context);
        }

        return $this->updateContent($grade, $validated, $actor, $context);
    }

    private function transition(AcademicGrade $grade, string $action, User $actor, AuditRequestContext $context): AcademicGrade
    {
        return DB::transaction(function () use ($grade, $action, $actor, $context): AcademicGrade {
            $lockedGrade = AcademicGrade::query()->whereKey($grade->id)->lockForUpdate()->firstOrFail();
            $requiredStatus = self::REQUIRED_CURRENT_STATUS[$action];

            if ($lockedGrade->status !== $requiredStatus) {
                throw ValidationException::withMessages([
                    'action' => "This action requires the grade to currently be '{$requiredStatus->value}'; ".
                        "it is currently '{$lockedGrade->status->value}'.",
                ]);
            }

            $beforeValues = self::snapshot($lockedGrade);
            $targetStatus = self::TARGET_STATUS[$action];

            $lockedGrade->update([
                'status' => $targetStatus,
                'submitted_at' => $targetStatus === GradeStatus::Submitted ? now() : $lockedGrade->submitted_at,
                'locked_at' => $targetStatus === GradeStatus::Locked ? now() : $lockedGrade->locked_at,
            ]);
            $lockedGrade->refresh();

            $this->auditRecorder->record(
                $actor,
                self::AUDIT_ACTION[$action],
                AuditableType::ACADEMIC_GRADE,
                $lockedGrade->id,
                $beforeValues,
                self::snapshot($lockedGrade),
                null,
                $context,
            );

            if ($action === 'lock') {
                Notification::create([
                    'user_id' => $lockedGrade->student->user_id,
                    'type' => NotificationType::AcademicGradeLocked,
                    'message' => "Your grade for {$lockedGrade->subject->code} has been finalized.",
                ]);

                // Locking the last grade of a year moves the student up right away
                // (ADR 0028) so the reclassification below already sees their new
                // year level; the daily command is only the backstop.
                $this->promoter->promoteStudent($lockedGrade->student, $actor, $context);

                // Reclassification needs a "now" to measure the student's
                // completed semesters against. With no term currently
                // semester_ongoing (e.g. between terms, or a historical
                // grade correction), there is nothing to compare against —
                // skip rather than guess.
                $currentTerm = AcademicTerm::query()
                    ->where('status', AcademicTermStatus::SemesterOngoing)
                    ->first()
                    ?? AcademicTerm::query()
                        ->whereIn('status', [AcademicTermStatus::ForDeanApproval, AcademicTermStatus::Draft])
                        ->latest('id')
                        ->first();

                if ($currentTerm !== null) {
                    $this->reclassifier->execute($lockedGrade->student, $currentTerm, $actor, $context);
                }
            }

            return $lockedGrade->refresh()->load(['student', 'subject', 'section']);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function updateContent(AcademicGrade $grade, array $validated, User $actor, AuditRequestContext $context): AcademicGrade
    {
        return DB::transaction(function () use ($grade, $validated, $actor, $context): AcademicGrade {
            $lockedGrade = AcademicGrade::query()->whereKey($grade->id)->lockForUpdate()->firstOrFail();

            $isIncCompletion = false;
            if (! $lockedGrade->status->isEditableByEncoder()) {
                // Incomplete (INC) grades remain editable by professors for up to 3 consecutive semesters
                if ($lockedGrade->mark === GradeMark::Incomplete) {
                    $currentTerm = AcademicTerm::query()
                        ->where('status', AcademicTermStatus::SemesterOngoing)
                        ->first();
                    $gradeTerm = $lockedGrade->academicTerm;
                    if ($currentTerm !== null) {
                        $elapsed = $currentTerm->termsElapsedSince($gradeTerm);
                        if ($elapsed <= 3) {
                            $isIncCompletion = true;
                        }
                    }
                }

                if (! $isIncCompletion) {
                    throw ValidationException::withMessages([
                        'mark' => "This grade is '{$lockedGrade->status->value}' and can no longer be edited directly.",
                    ]);
                }
            }

            $beforeValues = self::snapshot($lockedGrade);

            if (array_key_exists('mark', $validated)) {
                $mark = $validated['mark'] !== null ? GradeMark::from($validated['mark']) : null;
            } else {
                $mark = $lockedGrade->mark;
            }

            $targetStatus = $lockedGrade->status;
            if ($isIncCompletion && $mark !== null && $mark !== GradeMark::Incomplete) {
                $targetStatus = GradeStatus::Locked;
            }

            $lockedGrade->update([
                'status' => $targetStatus,
                'mark' => $mark,
                'final_grade' => $mark?->numericValue(),
                'remarks' => array_key_exists('remarks', $validated) ? $validated['remarks'] : $lockedGrade->remarks,
            ]);
            $lockedGrade->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::ACADEMIC_GRADE_UPDATED,
                AuditableType::ACADEMIC_GRADE,
                $lockedGrade->id,
                $beforeValues,
                self::snapshot($lockedGrade),
                null,
                $context,
            );

            if ($isIncCompletion && $mark !== null && $mark !== GradeMark::Incomplete) {
                // Resolving an INC can complete the year (ADR 0028), same as a fresh lock.
                $this->promoter->promoteStudent($lockedGrade->student, $actor, $context);

                $currentTerm = AcademicTerm::query()
                    ->where('status', AcademicTermStatus::SemesterOngoing)
                    ->first();

                if ($currentTerm !== null) {
                    $this->reclassifier->execute($lockedGrade->student, $currentTerm, $actor, $context);
                }
            }

            return $lockedGrade->refresh()->load(['student', 'subject', 'section']);
        });
    }

    /**
     * @return array{status: string, mark: ?string, final_grade: ?string, remarks: ?string, submitted_at: ?string, locked_at: ?string}
     */
    private static function snapshot(AcademicGrade $grade): array
    {
        return [
            'status' => $grade->status->value,
            'mark' => $grade->mark?->value,
            'final_grade' => $grade->final_grade,
            'remarks' => $grade->remarks,
            'submitted_at' => $grade->submitted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'locked_at' => $grade->locked_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
