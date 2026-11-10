<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Notification;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batch-locks submitted grades for an academic term (and optionally a college),
 * transitioning each from `submitted` → `locked`. This action is reserved for
 * the Registrar Head.
 *
 * For each grade locked:
 *   - `status` transitions to `locked` and `locked_at` is set to `now()`.
 *   - An audit log row is recorded (`academic_grade.locked`).
 *   - An official notification is dispatched to the student.
 *
 * Finally, all affected students are reclassified in bulk via
 * `ReclassifyStudentEnrollmentCategory::executeMany()`.
 */
final readonly class LockAllAcademicGrades
{
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private AuditRecorder $auditRecorder,
        private ReclassifyStudentEnrollmentCategory $reclassifier,
        private PromoteEligibleStudents $promoter,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{locked_count: int}
     */
    public function execute(array $filters, User $actor, AuditRequestContext $context): array
    {
        return DB::transaction(function () use ($filters, $actor, $context): array {
            $academicTermId = isset($filters['academic_term_id']) ? (int) $filters['academic_term_id'] : null;
            $college = isset($filters['college']) ? strtolower(trim((string) $filters['college'])) : null;
            $gradeIds = isset($filters['grade_ids']) && is_array($filters['grade_ids'])
                ? array_map('intval', $filters['grade_ids'])
                : null;

            $applyTermFilter = $academicTermId !== null
                && AcademicGrade::query()
                    ->where('status', GradeStatus::Submitted)
                    ->where('academic_term_id', $academicTermId)
                    ->exists();

            $query = AcademicGrade::query()
                ->where('status', GradeStatus::Submitted)
                ->when($applyTermFilter, fn ($q) => $q->where('academic_term_id', $academicTermId))
                ->when($gradeIds !== null && count($gradeIds) > 0, fn ($q) => $q->whereIn('id', $gradeIds))
                ->when($college !== null && $college !== '' && $college !== 'all', function ($query) use ($college) {
                    $query->where(function ($q) use ($college) {
                        $q->whereHas('section.sectionPlan', fn ($spq) => $spq->where('college', $college))
                            ->orWhereHas('subject', fn ($subq) => $subq->where('college', $college))
                            ->orWhereHas('student.program', fn ($pq) => $pq->where('college', $college));
                    });
                });

            $lockedAt = now()->startOfSecond();
            $lockedAtIso = $lockedAt->utc()->format('Y-m-d\TH:i:s\Z');
            $lockedCount = 0;

            /** @var array<int, true> $studentIds */
            $studentIds = [];

            // Chunked and bulk-written: a term-wide lock is tens of thousands of
            // grades, which a row-by-row loop cannot finish inside a request.
            $query
                ->with(['student:id,user_id', 'subject:id,code'])
                ->lockForUpdate()
                ->chunkById(self::CHUNK_SIZE, function (Collection $grades) use ($actor, $context, $lockedAt, $lockedAtIso, &$lockedCount, &$studentIds): void {
                    $auditEntries = [];
                    $notifications = [];
                    $now = now();

                    foreach ($grades as $grade) {
                        $before = self::snapshot($grade);

                        $auditEntries[] = [
                            'id' => $grade->id,
                            'before' => $before,
                            'after' => [...$before, 'status' => GradeStatus::Locked->value, 'locked_at' => $lockedAtIso],
                        ];

                        $notifications[] = [
                            'user_id' => $grade->student->user_id,
                            'type' => NotificationType::AcademicGradeLocked->value,
                            'message' => "Your grade for {$grade->subject->code} has been finalized.",
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $studentIds[$grade->student_id] = true;
                    }

                    AcademicGrade::query()
                        ->whereIn('id', $grades->modelKeys())
                        ->update(['status' => GradeStatus::Locked, 'locked_at' => $lockedAt, 'updated_at' => $now]);

                    $this->auditRecorder->recordMany(
                        $actor,
                        AuditAction::ACADEMIC_GRADE_LOCKED,
                        AuditableType::ACADEMIC_GRADE,
                        $auditEntries,
                        $context,
                    );

                    Notification::query()->insert($notifications);

                    $lockedCount += $grades->count();
                });

            if ($lockedCount === 0) {
                return ['locked_count' => 0];
            }

            /** @var Collection<int, StudentProfile> $students */
            $students = StudentProfile::query()->whereIn('id', array_keys($studentIds))->get();

            // A batch lock can finish a year for many students at once. Promote them
            // first (ADR 0028) so the reclassification below groups them by their NEW
            // year level; each promoteStudent() call also updates the in-memory model.
            foreach ($students as $student) {
                $this->promoter->promoteStudent($student, $actor, $context);
            }

            // Reclassification needs a "now" to measure the student's completed semesters against.
            $currentTerm = AcademicTerm::query()
                ->where('status', AcademicTermStatus::SemesterOngoing)
                ->first()
                ?? AcademicTerm::query()
                    ->whereIn('status', [AcademicTermStatus::ForDeanApproval, AcademicTermStatus::Draft])
                    ->latest('id')
                    ->first();

            if ($currentTerm !== null && $students->isNotEmpty()) {
                $this->reclassifier->executeMany($students, $currentTerm, $actor, $context);
            }

            return ['locked_count' => $lockedCount];
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
