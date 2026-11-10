<?php

namespace App\Actions\Enrollment;

use App\Actions\Academic\ClassifyEnrollmentStanding;
use App\Actions\Academic\ReclassifyStudentEnrollmentCategory;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentAccessContext;
use App\Domain\Enrollment\EnrollmentAudience;
use App\Domain\Enrollment\EnrollmentWindowResolver;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermEnrollmentWindow;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Resolves all five audience windows for a term in one query, so callers
 * can answer block-access questions without re-reading windows per section.
 *
 * Like `BuildEnrollmentScheduleSummary`, this never writes: a term with no
 * `academic_term_enrollment_windows` rows falls back to the term-wide
 * window in memory. Both actions decide openness through the same pure
 * `EnrollmentWindowResolver`, which is the single definition of the rule.
 *
 * `enrollment_category` self-heals here (spec
 * docs/superpowers/specs/2026-08-19-term-scoped-enrollment-standing-design.md):
 * only for the live `semester_ongoing` term, the freshly computed verdict
 * — not the possibly-stale stored column — decides THIS request's
 * `viewerAudience`, so routing is always correct even if nothing has
 * triggered a write yet. Persisting the fresh value is a secondary,
 * best-effort step via `ReclassifyStudentEnrollmentCategory` (which already
 * guards against no-op writes) attributed to a synthetic system actor, the
 * same pattern `students:reclassify` already uses — if no `registrar_head`
 * user exists to attribute the audit to, the write is skipped, never
 * thrown, since routing already used the live value regardless. Browsing
 * an archived/closed term always uses the stored column as-is.
 */
final class BuildEnrollmentAccessContext
{
    public function __construct(
        private readonly ClassifyEnrollmentStanding $classifier,
        private readonly ReclassifyStudentEnrollmentCategory $reclassifier,
    ) {}

    public function execute(AcademicTerm $term, StudentProfile $student): EnrollmentAccessContext
    {
        $now = CarbonImmutable::now();
        $windows = AcademicTermEnrollmentWindow::query()
            ->where('academic_term_id', $term->id)
            ->get()
            ->keyBy(fn (AcademicTermEnrollmentWindow $window): string => $window->audience->value);

        $openAudiences = [];
        foreach (EnrollmentAudience::cases() as $audience) {
            $window = $windows->get($audience->value);

            $availability = EnrollmentWindowResolver::resolve(
                $term->status,
                $term->enrollment_opens_at,
                $term->enrollment_closes_at,
                $window?->opens_at,
                $window?->closes_at,
                $now,
            );

            if ($availability->isOpen) {
                $openAudiences[] = $audience;
            }
        }

        $enrollmentCategory = $this->liveEnrollmentCategory($term, $student);

        $viewerAudience = EnrollmentAudience::forStudent(
            $enrollmentCategory,
            $student->year_level,
        );

        return new EnrollmentAccessContext(
            $viewerAudience,
            in_array($viewerAudience, $openAudiences, true),
            in_array(EnrollmentAudience::Irregular, $openAudiences, true),
            $openAudiences,
        );
    }

    private function liveEnrollmentCategory(AcademicTerm $term, StudentProfile $student): ?string
    {
        if ($term->status !== AcademicTermStatus::SemesterOngoing) {
            return $student->enrollment_category;
        }

        $verdict = $this->classifier->classify($student, $term);

        if ($verdict === null) {
            return $student->enrollment_category;
        }

        if ($student->enrollment_category !== $verdict->category->value) {
            $this->persistBestEffort($student, $term);
        }

        return $verdict->category->value;
    }

    private function persistBestEffort(StudentProfile $student, AcademicTerm $term): void
    {
        $systemActor = User::query()->where('role', UserRole::RegistrarHead)->first();

        if ($systemActor === null) {
            return;
        }

        try {
            $this->reclassifier->execute(
                $student,
                $term,
                $systemActor,
                new AuditRequestContext('enrollment-access-self-heal', null),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
