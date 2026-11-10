<?php

namespace Database\Seeders;

use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\Program;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds GRC's real 12-program catalog (the only programs the supplied
 * 2024-2029 curriculum schedules cover, across the four currently supported
 * colleges — see GrcCurriculumSeeder), plus one collegeless fixture used
 * only for local development and automated tests: `BSCRIM` (proves
 * learner-scoped vs. planning roles see different program lists).
 * `DemoEnrollmentSeeder`'s grade-history roster is placed on the real
 * `BSIT` program's own active curriculum, not a dedicated fixture.
 *
 * `BEED`/`BSED-*`/`TCP` program codes must match
 * `SectionBlockCode::coePrefix()`'s match arms exactly — that is how a
 * generated block section knows which COE major it belongs to.
 */
final class ProgramSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, status: ProgramStatus, college: ?CollegeCode}>
     */
    private const PROGRAMS = [
        ['code' => 'BSIT', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs],
        ['code' => 'BEED', 'name' => 'Bachelor of Elementary Education', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Coe],
        ['code' => 'BSED-FIL', 'name' => 'Bachelor of Secondary Education major in Filipino', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Coe],
        ['code' => 'BSED-ENG', 'name' => 'Bachelor of Secondary Education major in English', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Coe],
        ['code' => 'BSED-SOCSCI', 'name' => 'Bachelor of Secondary Education major in Social Studies', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Coe],
        ['code' => 'BSED-VAL', 'name' => 'Bachelor of Secondary Education major in Values Education', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Coe],
        ['code' => 'TCP', 'name' => 'Teacher Certificate Program', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Coe],
        ['code' => 'BSBA-FM', 'name' => 'BS Business Administration major in Financial Management', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Cbae],
        ['code' => 'BSENTREP', 'name' => 'BS Entrepreneurship', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Cbae],
        ['code' => 'BSBA-MM', 'name' => 'BS Business Administration major in Marketing Management', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Cbae],
        ['code' => 'BSBA-HRM', 'name' => 'BS Business Administration major in Human Resource Management', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Cbae],
        ['code' => 'BSA', 'name' => 'BS Accountancy', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Coa],
        // Deliberately inactive: proves learner-scoped and planning roles
        // receive different GET /api/v1/programs results. Left collegeless —
        // Criminology is outside the four currently supported colleges.
        ['code' => 'BSCRIM', 'name' => 'BS Criminology', 'status' => ProgramStatus::Inactive, 'college' => null],
    ];

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            foreach (self::PROGRAMS as $program) {
                Program::updateOrCreate(
                    ['code' => $program['code']],
                    ['name' => $program['name'], 'status' => $program['status'], 'college' => $program['college']],
                );
            }
        });
    }

    /**
     * Synthetic reference data must never reach a production-like environment.
     */
    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'ProgramSeeder may only run in the local or testing environment. '
                .'Refusing to seed synthetic program data into "'.app()->environment().'".',
            );
        }
    }
}
