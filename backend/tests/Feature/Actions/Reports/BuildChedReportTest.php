<?php

namespace Tests\Feature\Actions\Reports;

use App\Actions\Reports\BuildChedReport;
use App\Domain\Academic\GradeStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\Gender;
use App\Domain\Reports\ChedReport;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\EnrollmentSubject;
use App\Models\Section;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsChedReportFixtures;
use Tests\TestCase;

final class BuildChedReportTest extends TestCase
{
    use BuildsChedReportFixtures;
    use RefreshDatabase;

    private function report(): BuildChedReport
    {
        return $this->app->make(BuildChedReport::class);
    }

    /**
     * @return list<string>
     */
    private function numbers(ChedReport $report, AcademicTerm $term): array
    {
        /** @var list<string> $numbers */
        $numbers = array_values(array_map('strval', array_column($this->report()->execute($report, $term)->rows, $report === ChedReport::EnrollmentList ? 'student_number' : 'surname')));

        return $numbers;
    }

    // ---- CHED Enrollment List -------------------------------------------------------------

    public function test_the_enrollment_list_has_only_students_officially_enrolled_in_the_term(): void
    {
        $term = $this->chedTerm('2025-2026', '2nd');
        $other = $this->chedTerm('2025-2026', '1st');
        $subject = $this->chedSubject('IT101', 'Intro to Computing');

        $enrolled = $this->chedStudent();
        $this->chedEnrol($enrolled, $term, [$subject]);

        $this->chedEnrol($this->chedStudent(), $other, [$subject]);
        $this->chedEnrol($this->chedStudent(), $term, [$subject], EnrollmentStatus::Withdrawn);
        $this->chedEnrol($this->chedStudent(), $term, [$subject], EnrollmentStatus::PendingPayment);
        $this->chedEnrol($this->chedStudent([], ['is_demo_account' => true]), $term, [$subject]);

        $result = $this->report()->execute(ChedReport::EnrollmentList, $term);

        $this->assertSame(1, $result->total);
        $this->assertSame([$enrolled->student_number], array_column($result->rows, 'student_number'));
    }

    public function test_an_enrollment_list_row_reads_like_the_template(): void
    {
        $term = $this->chedTerm();
        $program = $this->chedProgram('BSBA-FM', 'BS Business Administration major in Financial Management');
        $student = $this->chedStudent(
            ['first_name' => 'Juan', 'middle_initial' => 'D', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.', 'name' => 'Juan D. Dela Cruz Jr.'],
            ['year_level' => 2, 'gender' => Gender::Male, 'citizenship' => 'Filipino'],
            $program,
        );
        $this->chedEnrol($student, $term, [
            $this->chedSubject('PATH1', 'Fitness', 2.0),
            $this->chedSubject('IT101', 'Intro to Computing', 3.0),
            $this->chedSubject('MATH1', 'College Algebra', 3.0),
        ]);

        $row = $this->report()->execute(ChedReport::EnrollmentList, $term)->rows[0];

        $this->assertSame([
            'no' => 1,
            'program' => 'Bachelor of Science in Business Administration',
            'major' => 'Financial Management',
            'student_number' => $student->student_number,
            'first_name' => 'Juan',
            'middle_name' => 'D.',
            'surname' => 'Dela Cruz',
            'name_extension' => 'Jr.',
            'citizenship' => 'Filipino',
            'gender' => 'M',
            'year_level' => 2,
            'subjects' => 'Intro to Computing (3), College Algebra (3), Fitness (2)',
            'units' => '8',
            'remarks' => '',
        ], $row);
    }

    public function test_a_dropped_subject_is_not_listed_or_counted(): void
    {
        $term = $this->chedTerm();
        $student = $this->chedStudent();
        $this->chedEnrol($student, $term, [$this->chedSubject('IT101', 'Intro to Computing')]);
        $this->chedEnrol($this->chedStudent(), $term, [$this->chedSubject('IT102', 'Programming 1')]);
        // The same student also dropped a subject in the same enrollment.
        $enrollment = $student->enrollments()->firstOrFail();
        $section = Section::query()->create([
            'academic_term_id' => $term->id, 'subject_id' => $this->chedSubject('IT103', 'Dropped Subject')->id,
            'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Published,
        ]);
        EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id, 'section_id' => $section->id, 'status' => EnrollmentSubjectStatus::Dropped,
        ]);

        $rows = collect($this->report()->execute(ChedReport::EnrollmentList, $term)->rows)->keyBy('student_number');

        $this->assertSame('Intro to Computing (3)', $rows[$student->student_number]['subjects']);
        $this->assertSame('3', $rows[$student->student_number]['units']);
    }

    public function test_the_enrollment_list_is_ordered_by_program_then_surname(): void
    {
        $term = $this->chedTerm();
        $subject = $this->chedSubject('IT101', 'Intro to Computing');
        $it = $this->chedProgram('BSIT', 'BS Information Technology');
        $ba = $this->chedProgram('BSBA-FM', 'BS Business Administration major in Financial Management');

        foreach ([['Aquino', $it], ['Zamora', $ba], ['Abad', $ba]] as [$surname, $program]) {
            $this->chedEnrol($this->chedStudent(['last_name' => $surname, 'name' => "X {$surname}"], [], $program), $term, [$subject]);
        }

        $result = $this->report()->execute(ChedReport::EnrollmentList, $term);

        $this->assertSame(['Abad', 'Zamora', 'Aquino'], array_column($result->rows, 'surname'));
        $this->assertSame([1, 2, 3], array_column($result->rows, 'no'));
    }

    // ---- NSTP Form 2-B ---------------------------------------------------------------------

    public function test_nstp_enrollment_is_nstp_1_in_the_first_semester_and_nstp_2_in_the_second(): void
    {
        $t1 = $this->chedTerm('2025-2026', '1st');
        $t2 = $this->chedTerm('2025-2026', '2nd');
        $nstp1 = $this->chedSubject('NSTP 1', 'National Service Training Program 1');
        $nstp2 = $this->chedSubject('NSTP2', 'National Service Training Program 2');
        $it = $this->chedSubject('IT101', 'Intro to Computing');

        $named = fn (string $surname) => $this->chedStudent(['last_name' => $surname, 'name' => "X {$surname}"]);
        // 1st semester: NSTP 1 only (an NSTP 2 enrollee is not on the NSTP 1 list).
        $this->chedEnrol($named('Alpha'), $t1, [$nstp1, $it]);
        $this->chedEnrol($named('Stray'), $t1, [$nstp2]);
        // 2nd semester: NSTP 2 only (an NSTP 1 retaker is not on the NSTP 2 list).
        $this->chedEnrol($named('Bravo'), $t2, [$nstp2]);
        $this->chedEnrol($named('Retaker'), $t2, [$nstp1]);
        // Neither: no NSTP subject at all.
        $this->chedEnrol($named('Charlie'), $t2, [$it]);

        $this->assertSame(['Alpha'], $this->numbers(ChedReport::NstpEnrollment, $t1));
        $this->assertSame(['Bravo'], $this->numbers(ChedReport::NstpEnrollment, $t2));
    }

    public function test_a_term_that_is_neither_first_nor_second_semester_lists_any_nstp_subject(): void
    {
        $summer = $this->chedTerm('2025-2026', 'summer');
        $student = $this->chedStudent(['last_name' => 'Summerfield', 'name' => 'X Summerfield']);
        $this->chedEnrol($student, $summer, [$this->chedSubject('NSTP 1', 'National Service Training Program 1')]);

        $this->assertSame(['Summerfield'], $this->numbers(ChedReport::NstpEnrollment, $summer));
    }

    public function test_the_report_title_names_the_nstp_subject_of_the_semester(): void
    {
        $this->assertSame('NSTP 1 Enrollment (Form 2-B)', ChedReport::NstpEnrollment->titleFor('1st'));
        $this->assertSame('NSTP 2 Enrollment (Form 2-B)', ChedReport::NstpEnrollment->titleFor('2nd'));
        $this->assertSame('NSTP Enrollment (Form 2-B)', ChedReport::NstpEnrollment->titleFor('summer'));
        $this->assertSame('NSTP 2 Graduates (Form 2-B)', ChedReport::NstpGraduates->titleFor('1st'));
        $this->assertSame('CHED Enrollment List', ChedReport::EnrollmentList->titleFor('2nd'));
        $this->assertSame('NSTP Summary (Form 2-A)', ChedReport::NstpSummary->titleFor('2nd'));
    }

    public function test_an_nstp_row_reads_like_the_template(): void
    {
        $term = $this->chedTerm();
        $student = $this->chedStudent(
            ['first_name' => 'Maria', 'middle_initial' => 'L', 'last_name' => 'Reyes', 'email' => 'maria.reyes@grc.test', 'name' => 'Maria L. Reyes'],
            [
                'gender' => Gender::Female,
                'birth_date' => '1992-11-25',
                'street_barangay' => '12 Rizal St., Brgy. San Jose',
                'town_city' => 'Caloocan City',
                'province' => 'Metro Manila',
                'contact_number' => '09171234567',
            ],
            $this->chedProgram('BSBA-FM', 'BS Business Administration major in Financial Management'),
        );
        $this->chedEnrol($student, $term, [$this->chedSubject('NSTP2', 'National Service Training Program 2')]);

        $row = $this->report()->execute(ChedReport::NstpEnrollment, $term)->rows[0];

        $this->assertSame([
            'no' => 1,
            'serial_no' => '',
            'surname' => 'Reyes',
            'first_name' => 'Maria',
            'middle_name' => 'L.',
            'course' => 'Bachelor of Science in Business Administration major in Financial Management',
            'gender' => 'FEMALE',
            'birthdate' => '11/25/1992',
            'street_barangay' => '12 Rizal St., Brgy. San Jose',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'contact_number' => '09171234567',
            'email' => 'maria.reyes@grc.test',
        ], $row);
    }

    public function test_a_written_address_stands_in_for_the_street_when_the_split_is_empty(): void
    {
        $term = $this->chedTerm();
        $student = $this->chedStudent([], ['address' => '12 Rizal Ave, Caloocan City']);
        $this->chedEnrol($student, $term, [$this->chedSubject('NSTP 2', 'NSTP 2')]);

        $row = $this->report()->execute(ChedReport::NstpEnrollment, $term)->rows[0];

        $this->assertSame('12 Rizal Ave, Caloocan City', $row['street_barangay']);
        $this->assertSame('', $row['town_city']);
        $this->assertSame('', $row['province']);
        $this->assertSame('', $row['birthdate']);
    }

    public function test_nstp_graduates_are_the_students_with_a_locked_passing_nstp_2_grade_that_term(): void
    {
        $t1 = $this->chedTerm('2025-2026', '1st');
        $t2 = $this->chedTerm('2025-2026', '2nd');
        $nstp1 = $this->chedSubject('NSTP 1', 'National Service Training Program 1');
        $nstp2 = $this->chedSubject('NSTP 2', 'National Service Training Program 2');

        $named = fn (string $surname) => $this->chedStudent(['last_name' => $surname, 'name' => "X {$surname}"]);
        $this->chedGrade($named('Passed'), $nstp2, $t2, '3.00');
        $this->chedGrade($named('Honor'), $nstp2, $t2, '1.50');
        $this->chedGrade($named('Failed'), $nstp2, $t2, '5.00');
        $this->chedGrade($named('Draft'), $nstp2, $t2, '1.50', GradeStatus::Draft);
        $this->chedGrade($named('Submitted'), $nstp2, $t2, '1.50', GradeStatus::Submitted);
        $this->chedGrade($named('Incomplete'), $nstp2, $t2, 'INC');
        $this->chedGrade($named('OnlyNstpOne'), $nstp1, $t2, '1.50');
        $this->chedGrade($named('EarlierTerm'), $nstp2, $t1, '1.50');
        $this->chedGrade($this->chedStudent(['last_name' => 'Demo', 'name' => 'X Demo'], ['is_demo_account' => true]), $nstp2, $t2, '1.50');

        $this->assertSame(['Honor', 'Passed'], $this->numbers(ChedReport::NstpGraduates, $t2));
        $this->assertSame(['EarlierTerm'], $this->numbers(ChedReport::NstpGraduates, $t1));
    }

    // ---- paging and warnings ---------------------------------------------------------------

    public function test_a_page_keeps_counting_from_where_the_last_page_stopped(): void
    {
        $term = $this->chedTerm();
        $nstp = $this->chedSubject('NSTP 2', 'NSTP 2');
        foreach (['A', 'B', 'C', 'D', 'E'] as $surname) {
            $this->chedEnrol($this->chedStudent(['last_name' => $surname, 'name' => "X {$surname}"]), $term, [$nstp]);
        }

        $page = $this->report()->execute(ChedReport::NstpEnrollment, $term, page: 2, perPage: 2);

        $this->assertSame(5, $page->total);
        $this->assertSame(['C', 'D'], array_column($page->rows, 'surname'));
        $this->assertSame([3, 4], array_column($page->rows, 'no'));
    }

    public function test_it_counts_listed_students_without_a_gender_and_with_generated_details(): void
    {
        $term = $this->chedTerm();
        $nstp = $this->chedSubject('NSTP 2', 'NSTP 2');
        $this->chedEnrol($this->chedStudent([], ['gender' => null]), $term, [$nstp]);
        $this->chedEnrol($this->chedStudent([], ['demographics_demo_fields' => ['gender']]), $term, [$nstp]);
        $this->chedEnrol($this->chedStudent(), $term, [$nstp]);

        $result = $this->report()->execute(ChedReport::NstpEnrollment, $term, page: 1, perPage: 1);

        $this->assertSame(3, $result->total);
        $this->assertSame(1, $result->missingGender);
        $this->assertSame(1, $result->demoPopulated);
    }

    // ---- NSTP Form 2-A ---------------------------------------------------------------------

    public function test_the_summary_of_a_semester_has_only_that_semesters_numbers_and_the_rest_zero(): void
    {
        $t1 = $this->chedTerm('2025-2026', '1st');
        $t2 = $this->chedTerm('2025-2026', '2nd');
        $later = $this->chedTerm('2026-2027', '1st');
        $nstp1 = $this->chedSubject('NSTP 1', 'National Service Training Program 1');
        $nstp2 = $this->chedSubject('NSTP2', 'National Service Training Program 2');

        // 1st sem: NSTP 1, 2 male, 1 female. 2nd sem: NSTP 2, 1 male, 2 female, all three passed.
        foreach ([Gender::Male, Gender::Male, Gender::Female] as $gender) {
            $this->chedEnrol($this->chedStudent([], ['gender' => $gender]), $t1, [$nstp1]);
        }
        foreach ([Gender::Male, Gender::Female, Gender::Female] as $gender) {
            $student = $this->chedStudent([], ['gender' => $gender]);
            $this->chedEnrol($student, $t2, [$nstp2]);
            $this->chedGrade($student, $nstp2, $t2, '2.00');
        }
        // Never counted: another school year, a demo account.
        $this->chedEnrol($this->chedStudent([], ['gender' => Gender::Male]), $later, [$nstp1]);
        $this->chedEnrol($this->chedStudent([], ['gender' => Gender::Male, 'is_demo_account' => true]), $t1, [$nstp1]);
        // Counted in neither M nor F, and warned about, only for the semester it is enrolled in.
        $this->chedEnrol($this->chedStudent([], ['gender' => null]), $t1, [$nstp1]);

        $zero = ['m' => 0, 'f' => 0];

        $second = $this->report()->summary($t2);
        $this->assertSame('Global Reciprocal Colleges', $second['campus']);
        $this->assertSame('Private', $second['classification']);
        $this->assertSame('2025-2026', $second['academic_year']);
        $this->assertSame('2nd', $second['semester']);
        $this->assertSame($zero, $second['enrollment']['1st']['cwts']);
        $this->assertSame(['m' => 1, 'f' => 2], $second['enrollment']['2nd']['cwts']);
        $this->assertSame($zero, $second['enrollment']['summer']['cwts']);
        $this->assertSame(['m' => 1, 'f' => 2], $second['graduates']['cwts']);
        $this->assertSame(0, $second['missing_gender']);

        $first = $this->report()->summary($t1);
        $this->assertSame('1st', $first['semester']);
        $this->assertSame(['m' => 2, 'f' => 1], $first['enrollment']['1st']['cwts']);
        $this->assertSame($zero, $first['enrollment']['2nd']['cwts']);
        $this->assertSame($zero, $first['enrollment']['summer']['cwts']);
        $this->assertSame($zero, $first['graduates']['cwts'], 'nobody passed NSTP 2 in the first semester');
        $this->assertSame(1, $first['missing_gender']);

        foreach ([$first, $second] as $summary) {
            foreach (['1st', '2nd', 'summer'] as $semester) {
                $this->assertSame($zero, $summary['enrollment'][$semester]['rotc']);
                $this->assertSame($zero, $summary['enrollment'][$semester]['lts']);
            }
            $this->assertSame($zero, $summary['graduates']['rotc']);
            $this->assertSame($zero, $summary['graduates']['lts']);
        }
    }

    public function test_the_summary_of_a_summer_term_fills_the_summer_block(): void
    {
        $summer = $this->chedTerm('2025-2026', 'summer');
        $this->chedEnrol(
            $this->chedStudent([], ['gender' => Gender::Female]),
            $summer,
            [$this->chedSubject('NSTP 1', 'National Service Training Program 1')],
        );

        $summary = $this->report()->summary($summer);

        $this->assertSame(['m' => 0, 'f' => 1], $summary['enrollment']['summer']['cwts']);
        $this->assertSame(['m' => 0, 'f' => 0], $summary['enrollment']['1st']['cwts']);
    }

    public function test_the_summary_of_a_school_with_no_nstp_students_is_all_zero(): void
    {
        $summary = $this->report()->summary($this->chedTerm());

        $this->assertSame(['m' => 0, 'f' => 0], $summary['enrollment']['2nd']['cwts']);
        $this->assertSame(0, $summary['missing_gender']);
    }

    // ---- search ---------------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: string}
     */
    private function searchable(): array
    {
        $term = $this->chedTerm();
        $nstp = $this->chedSubject('NSTP 2', 'NSTP 2');
        foreach ([
            ['Ana', 'Santos', 'Ana D. Santos', '2025-08-10001'],
            ['Juan', 'Santos', 'Juan D. Santos', '2025-08-10002'],
            ['Maria', 'Reyes', 'Maria D. Reyes', '2025-08-10003'],
        ] as [$first, $last, $full, $number]) {
            $student = $this->chedStudent(
                ['first_name' => $first, 'last_name' => $last, 'name' => $full],
                ['student_number' => $number],
            );
            $this->chedEnrol($student, $term, [$nstp]);
        }

        return ['2025-2026', '2nd'];
    }

    public function test_a_search_keeps_the_students_whose_name_or_number_has_every_word(): void
    {
        $this->searchable();
        $term = $this->chedTerm();
        $surnames = fn (?string $search) => array_column(
            $this->report()->execute(ChedReport::NstpEnrollment, $term, search: $search)->rows,
            'first_name',
        );

        $this->assertSame(['Maria', 'Ana', 'Juan'], $surnames(null));
        $this->assertSame(['Maria', 'Ana', 'Juan'], $surnames('   '));
        $this->assertSame(['Ana', 'Juan'], $surnames('santos'));
        $this->assertSame(['Ana'], $surnames('SANTOS ana'));
        $this->assertSame(['Juan'], $surnames('Santos, Juan'));
        $this->assertSame(['Maria'], $surnames('10003'));
        $this->assertSame(['Maria'], $surnames('2025-08-10003'));
        $this->assertSame([], $surnames('nobody'));
    }

    public function test_a_search_counts_and_pages_only_the_students_it_found(): void
    {
        $this->searchable();
        $term = $this->chedTerm();

        $found = $this->report()->execute(ChedReport::NstpEnrollment, $term, page: 1, perPage: 1, search: 'santos');

        $this->assertSame(2, $found->total);
        $this->assertCount(1, $found->rows);
        $this->assertSame(1, $found->rows[0]['no']);

        $second = $this->report()->execute(ChedReport::NstpEnrollment, $term, page: 2, perPage: 1, search: 'santos');
        $this->assertSame(2, $second->rows[0]['no']);
    }

    public function test_a_search_cannot_use_sql_wildcards(): void
    {
        $this->searchable();
        $term = $this->chedTerm();

        $this->assertSame(0, $this->report()->execute(ChedReport::NstpEnrollment, $term, search: '%')->total);
        $this->assertSame(0, $this->report()->execute(ChedReport::NstpEnrollment, $term, search: '_')->total);
        $this->assertSame(0, $this->report()->execute(ChedReport::NstpEnrollment, $term, search: "x' OR '1'='1")->total);
    }

    public function test_the_student_profile_model_is_not_loaded_per_row(): void
    {
        $term = $this->chedTerm();
        $nstp = $this->chedSubject('NSTP 2', 'NSTP 2');
        foreach (range(1, 4) as $unused) {
            $this->chedEnrol($this->chedStudent(), $term, [$nstp]);
        }

        // Lazy loading is a hard error in the test environment (AppServiceProvider), so this
        // fails if a row reads a relation that was not eager-loaded.
        $this->assertCount(4, $this->report()->execute(ChedReport::NstpEnrollment, $term)->rows);
        $this->assertCount(4, $this->report()->execute(ChedReport::EnrollmentList, $term)->rows);
        $this->assertSame(4, StudentProfile::query()->count());
    }
}
