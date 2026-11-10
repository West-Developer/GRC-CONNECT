<?php

namespace App\Console\Commands;

use App\Actions\Academic\ReclassifyStudentEnrollmentCategory;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\ClassificationVerdict;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * One-time backfill for every locked grade recorded before automatic
 * Regular/Irregular classification existed (see ADR 0021,
 * `App\Actions\Academic\ClassifyEnrollmentStanding`).
 * `UpdateAcademicGrade::transition()` reclassifies going forward on every
 * new `lock`; this command catches up students whose grades were already
 * locked before that hook existed.
 */
final class ReclassifyStudentEnrollmentCategories extends Command
{
    protected $signature = 'students:reclassify
        {--student= : Reclassify a single student by student_number, instead of every student}
        {--dry-run : Report what would change without writing, auditing, or notifying anything}';

    protected $description = "Recompute every student's Regular/Irregular enrollment_category from their locked grade history";

    public function handle(ReclassifyStudentEnrollmentCategory $reclassifier): int
    {
        $currentTerm = AcademicTerm::query()
            ->where('status', AcademicTermStatus::SemesterOngoing)
            ->first();

        if ($currentTerm === null) {
            $this->error('No academic term is currently semester_ongoing — nothing to classify against.');

            return self::FAILURE;
        }

        $query = StudentProfile::query();
        $studentNumber = $this->option('student');

        if (is_string($studentNumber) && $studentNumber !== '') {
            $query->where('student_number', $studentNumber);
        }

        $students = $query->get();

        if ($students->isEmpty()) {
            $this->info('No matching students found.');

            return self::SUCCESS;
        }

        $before = $students->pluck('enrollment_category', 'id');

        if ($this->option('dry-run')) {
            [$verdicts, $undeterminedIds] = $reclassifier->previewWithUndetermined($students, $currentTerm);
            $this->reportChanges($before, $verdicts, $undeterminedIds);
            $this->comment('Dry run only — no changes were written.');

            return self::SUCCESS;
        }

        $actor = User::query()->where('role', UserRole::RegistrarHead)->first();

        if ($actor === null) {
            throw new RuntimeException(
                'students:reclassify requires at least one registrar_head user to attribute the audit trail to.',
            );
        }

        $context = new AuditRequestContext('students-reclassify-command', null);
        [$verdicts, $undeterminedIds] = $reclassifier->executeManyWithUndetermined($students, $currentTerm, $actor, $context);
        $this->reportChanges($before, $verdicts, $undeterminedIds);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, ?string>  $before
     * @param  array<int, ClassificationVerdict>  $verdicts
     * @param  list<int>  $undeterminedIds
     */
    private function reportChanges(Collection $before, array $verdicts, array $undeterminedIds): void
    {
        $changed = 0;

        foreach ($verdicts as $studentId => $verdict) {
            if (in_array($studentId, $undeterminedIds, true)) {
                continue;
            }

            $previousCategory = $before->get($studentId);

            if ($previousCategory === $verdict->category->value) {
                continue;
            }

            $changed++;
            $reasonSummary = $verdict->reasons === []
                ? ''
                : ' ('.implode('; ', array_column($verdict->reasons, 'message')).')';

            $this->line(sprintf(
                'Student #%d: %s -> %s%s',
                $studentId,
                $previousCategory ?? 'unset',
                $verdict->category->value,
                $reasonSummary,
            ));
        }

        $this->info(sprintf('%d of %d student(s) would change.', $changed, count($verdicts)));
    }
}
