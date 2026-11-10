<?php

namespace Database\Seeders;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds a small synthetic subject catalog for local development and automated
 * tests. This is NOT the real GRC subject catalog — codes, titles, and unit
 * counts are invented placeholders, and the status vocabulary remains
 * unconfirmed under PRD §17 (see App\Domain\Curriculum\SubjectStatus).
 *
 * The catalog is deliberately shaped as a coherent computing program so that
 * CurriculumSeeder can build a genuine multi-step prerequisite chain on top of
 * it rather than an arbitrary one — hence every row is tagged CCS.
 *
 * Lookup stays keyed on `code` alone (not the new `(college, code)` pair):
 * every code here is already known to be globally unique, so this also
 * backfills `college` in place on any row seeded before the column existed,
 * rather than risking a duplicate.
 */
final class SubjectSeeder extends Seeder
{
    /**
     * @var list<array{code: string, title: string, units: int, status: SubjectStatus}>
     */
    private const SUBJECTS = [
        ['code' => 'CS101', 'title' => 'Introduction to Computing', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'CS102', 'title' => 'Computer Programming 1', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'CS201', 'title' => 'Computer Programming 2', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'CS202', 'title' => 'Data Structures and Algorithms', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'CS301', 'title' => 'Database Management Systems', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'IT101', 'title' => 'Web Systems and Technologies', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'IT201', 'title' => 'Networking 1', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'MATH101', 'title' => 'College Algebra', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'MATH102', 'title' => 'Discrete Mathematics', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'GE101', 'title' => 'Purposive Communication', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'GE102', 'title' => 'Readings in Philippine History', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'PE101', 'title' => 'Physical Education 1', 'units' => 2, 'status' => SubjectStatus::Active],
        // Deliberately inactive: proves catalog filtering works and that an
        // inactive subject can still be referenced by historical records.
        ['code' => 'CS099', 'title' => 'Retired Computing Elective', 'units' => 3, 'status' => SubjectStatus::Inactive],
        // Year 3-4 core subjects, added so "BSCS Curriculum 2026" (see
        // CurriculumSeeder) covers all four years — the demo grade-history
        // roster (DemoEnrollmentSeeder) needs a real placement at every
        // ordinal from year 1 semester 1 through year 4 semester 2.
        ['code' => 'CS302', 'title' => 'Software Engineering', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'CS303', 'title' => 'Systems Analysis and Design', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'CS401', 'title' => 'Information Assurance and Security', 'units' => 3, 'status' => SubjectStatus::Active],
        ['code' => 'CS402', 'title' => 'Capstone Project', 'units' => 3, 'status' => SubjectStatus::Active],
    ];

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            foreach (self::SUBJECTS as $subject) {
                Subject::updateOrCreate(
                    ['code' => $subject['code']],
                    [
                        'college' => CollegeCode::Ccs,
                        'title' => $subject['title'],
                        'units' => $subject['units'],
                        'status' => $subject['status'],
                    ],
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
                'SubjectSeeder may only run in the local or testing environment. '
                .'Refusing to seed synthetic subject data into "'.app()->environment().'".',
            );
        }
    }
}
