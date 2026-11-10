<?php

namespace Database\Seeders;

use App\Actions\Organization\TransitionAcademicTerm;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\ScheduleProposal;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Not part of `DatabaseSeeder`'s default chain — run it explicitly with
 * `php artisan db:seed --class=EnrollmentOpenDemoSeeder` after the normal
 * seed. It fast-forwards CCS's schedule from `ProgramChairScheduleSampleSeeder`'s
 * "planned" sections straight to a real student-enrollable state, skipping
 * the Dean → Executive Director → publish approval chain a manual test
 * would otherwise have to click through by hand.
 *
 * CCS only: the five seeded student logins (DemoEnrollmentSeeder) are all
 * on the BSCS curriculum, so that is the one college worth fast-forwarding
 * for a block-enrollment demo. Directly writing the final `published`
 * status (rather than driving each approval action) matches how
 * `ProgramChairScheduleSampleSeeder` already seeds `dean_approved` proposals
 * for other colleges — this is seed data, not a substitute for the
 * approval-flow tests that already cover the real transitions.
 */
final class EnrollmentOpenDemoSeeder extends Seeder
{
    private const COLLEGE = 'ccs';

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            $term = AcademicTerm::query()
                ->whereIn('status', [AcademicTermStatus::Draft, AcademicTermStatus::ForDeanApproval])
                ->latest('id')
                ->first();

            if ($term === null) {
                throw new RuntimeException(
                    'EnrollmentOpenDemoSeeder requires a Draft or For-Dean-Approval current term. Run the default seed first.',
                );
            }

            $proposal = ScheduleProposal::query()
                ->where('academic_term_id', $term->id)
                ->where('college', self::COLLEGE)
                ->first();

            if ($proposal === null) {
                throw new RuntimeException(
                    'EnrollmentOpenDemoSeeder requires a CCS schedule proposal. Run ProgramChairScheduleSampleSeeder first.',
                );
            }

            $sectionIds = Section::query()
                ->where('academic_term_id', $term->id)
                ->whereHas('sectionPlan', fn ($query) => $query->where('college', self::COLLEGE))
                ->pluck('id');

            Section::query()
                ->whereIn('id', $sectionIds)
                ->update(['status' => SectionStatus::Published]);

            $proposal->update([
                'status' => ScheduleProposalStatus::Published,
                'decided_by' => $this->userWithRole(UserRole::ExecutiveDirector)->id,
                'decided_at' => now(),
            ]);

            $registrar = $this->userWithRole(UserRole::RegistrarHead);
            app(TransitionAcademicTerm::class)->execute(
                $term,
                'open_enrollment',
                $registrar,
                new AuditRequestContext('enrollment-open-demo-seed', null),
            );
        });
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::query()->where('role', $role)->first();

        if ($user === null) {
            throw new RuntimeException("EnrollmentOpenDemoSeeder requires a '{$role->value}' user. Run RoleUserSeeder first.");
        }

        return $user;
    }

    /**
     * Synthetic reference data must never reach a production-like environment.
     */
    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'EnrollmentOpenDemoSeeder may only run in the local or testing environment. '
                .'Refusing to seed synthetic schedule state into "'.app()->environment().'".',
            );
        }
    }
}
