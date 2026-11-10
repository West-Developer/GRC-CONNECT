<?php

namespace App\Actions\Organization;

use App\Actions\Enrollment\BuildEnrollmentAccessContext;
use App\Domain\Enrollment\AddDropWindowResolver;
use App\Domain\Enrollment\AudienceAvailability;
use App\Domain\Enrollment\EnrollmentAudience;
use App\Domain\Enrollment\EnrollmentAvailability;
use App\Domain\Enrollment\EnrollmentScheduleSummary;
use App\Domain\Enrollment\EnrollmentWindowResolver;
use App\Domain\Identity\UserRole;
use App\Models\AcademicTerm;
use App\Models\AcademicTermEnrollmentWindow;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads never write: a term created before `academic_term_enrollment_windows`
 * existed (or whose rows were otherwise never saved) has no rows at all, so
 * every audience here just falls back to the term-wide window live, in
 * memory, rather than lazily inserting rows on a GET. `SaveEnrollmentSchedule`
 * is what actually persists rows, once the Registrar Head saves a schedule.
 */
final class BuildEnrollmentScheduleSummary
{
    public function __construct(private readonly BuildEnrollmentAccessContext $accessContext) {}

    public function execute(AcademicTerm $term, ?User $viewer): EnrollmentScheduleSummary
    {
        $now = CarbonImmutable::now();
        $existingWindows = AcademicTermEnrollmentWindow::query()
            ->where('academic_term_id', $term->id)
            ->get()
            ->keyBy(fn (AcademicTermEnrollmentWindow $window): string => $window->audience->value);

        $audiences = array_map(
            fn (EnrollmentAudience $audience): AudienceAvailability => new AudienceAvailability(
                $audience,
                $this->resolve($term, $existingWindows->get($audience->value), $now),
            ),
            EnrollmentAudience::cases(),
        );

        return new EnrollmentScheduleSummary(
            $term->id,
            $term->status,
            $term->enrollment_opens_at,
            $term->enrollment_closes_at,
            $audiences,
            $this->viewerAvailability($term, $viewer, $existingWindows, $now),
            AddDropWindowResolver::resolve(
                $term->status,
                $term->enrollment_closes_at,
                $term->add_drop_deadline_at,
                $now,
                false,
                $term->add_drop_opens_at,
            ),
        );
    }

    /**
     * @param  Collection<string, AcademicTermEnrollmentWindow>  $existingWindows
     */
    private function viewerAvailability(
        AcademicTerm $term,
        ?User $viewer,
        Collection $existingWindows,
        CarbonImmutable $now,
    ): ?AudienceAvailability {
        if ($viewer === null || $viewer->role !== UserRole::Student) {
            return null;
        }

        $studentProfile = StudentProfile::query()->where('user_id', $viewer->id)->first();

        if ($studentProfile === null) {
            return null;
        }

        // The live standing decides the audience (ADR 0028), exactly as it does for
        // the block and eligible-subject pools, so this banner can never say
        // "Regular" while those pools already treat the student as Irregular.
        $audience = $this->accessContext->execute($term, $studentProfile)->viewerAudience;

        return new AudienceAvailability(
            $audience,
            $this->resolve($term, $existingWindows->get($audience->value), $now),
        );
    }

    private function resolve(
        AcademicTerm $term,
        ?AcademicTermEnrollmentWindow $window,
        CarbonImmutable $now,
    ): EnrollmentAvailability {
        return EnrollmentWindowResolver::resolve(
            $term->status,
            $term->enrollment_opens_at,
            $term->enrollment_closes_at,
            $window?->opens_at,
            $window?->closes_at,
            $now,
        );
    }
}
