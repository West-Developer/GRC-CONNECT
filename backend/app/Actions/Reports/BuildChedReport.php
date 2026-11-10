<?php

namespace App\Actions\Reports;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\Gender;
use App\Domain\Identity\PersonName;
use App\Domain\Reports\ChedProgramName;
use App\Domain\Reports\ChedReport;
use App\Domain\Reports\ChedReportRows;
use App\Models\AcademicTerm;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The data behind the four CHED / NSTP reports (ADR 0049). The preview, the CSV and the Excel
 * file all read the rows built here, so they can never disagree.
 *
 * Who is counted (never a demo account, same as honors and attrition):
 *  - enrollment list: an `enrolled` enrollment in the term;
 *  - NSTP enrollment: that, with a not-dropped NSTP subject (code `NSTP...` once spaces are removed):
 *    NSTP 1 in a first-semester term, NSTP 2 in a second-semester term, any NSTP subject in another;
 *  - NSTP graduates: a LOCKED, passing grade for NSTP 2 in the term;
 *  - Form 2-A: the NSTP enrollment and graduates of the ONE chosen term, split by gender. Only that
 *    term's semester block has numbers; every other block is 0.
 */
final class BuildChedReport
{
    private const IDS_PER_QUERY = 1000;

    /**
     * @param  ?string  $search  narrows the list to students whose name or student number contains every
     *                           word of it (the on-screen search; a download never passes one)
     * @return ChedReportRows one page when `$page` is given, otherwise every row
     */
    public function execute(ChedReport $report, AcademicTerm $term, ?int $page = null, int $perPage = 25, ?string $search = null): ChedReportRows
    {
        $filter = $this->students($report, [$term->id], $this->nstpPrefix($term));

        if ($search !== null && trim($search) !== '') {
            $this->matching($filter, $search);
        }

        $total = (clone $filter)->count();
        $missingGender = (clone $filter)->whereNull('student_profiles.gender')->count();
        $demoPopulated = (clone $filter)->whereNotNull('student_profiles.demographics_demo_fields')->count();

        $ordered = $this->ordered($filter, $report);
        $offset = 0;

        if ($page !== null) {
            $ordered->forPage($page, $perPage);
            $offset = ($page - 1) * $perPage;
        }

        /** @var Collection<int, StudentProfile> $students */
        $students = $ordered->with(['user', 'program'])->get();

        $rows = $report === ChedReport::EnrollmentList
            ? $this->enrollmentListRows($students, $term, $offset)
            : $this->nstpRows($students, $offset);

        return new ChedReportRows($rows, $total, $missingGender, $demoPopulated);
    }

    /**
     * Form 2-A for the ONE chosen term: only that term's semester block (and the graduates of that term)
     * carries numbers, every other block is 0. CWTS only (GRC offers CWTS), ROTC and LTS are 0.
     *
     * @return array{
     *     campus: string, classification: string, academic_year: string, semester: string,
     *     enrollment: array<string, array<string, array{m: int, f: int}>>,
     *     graduates: array<string, array{m: int, f: int}>,
     *     missing_gender: int,
     * }
     */
    public function summary(AcademicTerm $term): array
    {
        $none = ['m' => 0, 'f' => 0];
        $prefix = $this->nstpPrefix($term);

        $enrollment = [
            '1st' => $this->components($none),
            '2nd' => $this->components($none),
            'summer' => $this->components($none),
        ];
        $block = $this->semesterBlock($term);

        if ($block !== null) {
            $enrollment[$block] = $this->components(
                $this->genderCounts($this->students(ChedReport::NstpEnrollment, [$term->id], $prefix)),
            );
        }

        $missing = StudentProfile::query()
            ->where('student_profiles.is_demo_account', false)
            ->whereNull('student_profiles.gender')
            ->where(function (Builder $query) use ($term, $prefix): void {
                $query->whereExists($this->enrolledInNstp([$term->id], $prefix))
                    ->orWhereExists($this->passedNstp2([$term->id]));
            })
            ->count();

        return [
            'campus' => (string) config('ched.school_name'),
            'classification' => (string) config('ched.classification'),
            'academic_year' => $term->school_year,
            'semester' => $term->semester,
            'enrollment' => $enrollment,
            'graduates' => $this->components($this->genderCounts($this->students(ChedReport::NstpGraduates, [$term->id]))),
            'missing_gender' => $missing,
        ];
    }

    /** The Form 2-A block a term belongs to: `1st`, `2nd` or `summer`; null for a term that is none of them. */
    private function semesterBlock(AcademicTerm $term): ?string
    {
        $semester = strtolower(trim($term->semester));

        return match (true) {
            $semester === '1st' => '1st',
            $semester === '2nd' => '2nd',
            str_contains($semester, 'summer') => 'summer',
            default => null,
        };
    }

    /**
     * The NSTP subject a term's enrollment list is about, as the start of a normalised subject code:
     * NSTP 1 in the first semester, NSTP 2 in the second, any NSTP subject otherwise.
     */
    private function nstpPrefix(AcademicTerm $term): string
    {
        return match (strtolower(trim($term->semester))) {
            '1st' => 'NSTP1',
            '2nd' => 'NSTP2',
            default => 'NSTP',
        };
    }

    /**
     * Keeps the students whose name or student number contains every word of `$search`.
     *
     * @param  Builder<StudentProfile>  $filter
     */
    private function matching(Builder $filter, string $search): void
    {
        $words = preg_split('/[\s,]+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';

            $filter->where(function (Builder $any) use ($like): void {
                $any->where('student_profiles.student_number', 'like', $like)
                    ->orWhereIn('student_profiles.user_id', static function (QueryBuilder $users) use ($like): void {
                        $users->select('users.id')->from('users')->where(function (QueryBuilder $name) use ($like): void {
                            $name->where('users.name', 'like', $like)
                                ->orWhere('users.first_name', 'like', $like)
                                ->orWhere('users.last_name', 'like', $like);
                        });
                    });
            });
        }
    }

    /**
     * CWTS is the only NSTP component GRC offers: ROTC and LTS are always zero.
     *
     * @param  array{m: int, f: int}  $cwts
     * @return array{rotc: array{m: int, f: int}, cwts: array{m: int, f: int}, lts: array{m: int, f: int}}
     */
    private function components(array $cwts): array
    {
        $none = ['m' => 0, 'f' => 0];

        return ['rotc' => $none, 'cwts' => $cwts, 'lts' => $none];
    }

    /**
     * @param  list<int>  $termIds
     * @return Builder<StudentProfile>
     */
    private function students(ChedReport $report, array $termIds, string $nstpPrefix = 'NSTP'): Builder
    {
        $query = StudentProfile::query()->where('student_profiles.is_demo_account', false);

        return match ($report) {
            ChedReport::EnrollmentList => $query->whereExists($this->enrolled($termIds)),
            ChedReport::NstpEnrollment => $query->whereExists($this->enrolledInNstp($termIds, $nstpPrefix)),
            ChedReport::NstpGraduates => $query->whereExists($this->passedNstp2($termIds)),
            ChedReport::NstpSummary => throw new \InvalidArgumentException('The summary has no student list.'),
        };
    }

    /**
     * @param  Builder<StudentProfile>  $filter
     * @return Builder<StudentProfile>
     */
    private function ordered(Builder $filter, ChedReport $report): Builder
    {
        $query = $filter
            ->select('student_profiles.*')
            ->join('users', 'users.id', '=', 'student_profiles.user_id');

        if ($report === ChedReport::EnrollmentList) {
            $query->join('programs', 'programs.id', '=', 'student_profiles.program_id')
                ->orderBy('programs.name');
        }

        return $query
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->orderBy('student_profiles.id');
    }

    /**
     * @param  Builder<StudentProfile>  $filter
     * @return array{m: int, f: int}
     */
    private function genderCounts(Builder $filter): array
    {
        // `toBase()`: a plain query, so `gender` comes back as the stored letter, not the enum cast.
        $counts = (clone $filter)
            ->toBase()
            ->selectRaw('student_profiles.gender as gender, COUNT(*) as total')
            ->groupBy('student_profiles.gender')
            ->pluck('total', 'gender');

        return [
            'm' => (int) ($counts[Gender::Male->value] ?? 0),
            'f' => (int) ($counts[Gender::Female->value] ?? 0),
        ];
    }

    /**
     * @param  list<int>  $termIds
     */
    private function enrolled(array $termIds): \Closure
    {
        return function (QueryBuilder $sub) use ($termIds): void {
            $sub->selectRaw('1')
                ->from('enrollments as e')
                ->whereColumn('e.student_id', 'student_profiles.id')
                ->whereIn('e.academic_term_id', $termIds)
                ->where('e.status', EnrollmentStatus::Enrolled->value);
        };
    }

    /**
     * @param  list<int>  $termIds
     */
    private function enrolledInNstp(array $termIds, string $prefix): \Closure
    {
        return function (QueryBuilder $sub) use ($termIds, $prefix): void {
            $sub->selectRaw('1')
                ->from('enrollments as e')
                ->join('enrollment_subjects as es', 'es.enrollment_id', '=', 'e.id')
                ->join('sections as sec', 'sec.id', '=', 'es.section_id')
                ->join('subjects as sub', 'sub.id', '=', 'sec.subject_id')
                ->whereColumn('e.student_id', 'student_profiles.id')
                ->whereIn('e.academic_term_id', $termIds)
                ->where('e.status', EnrollmentStatus::Enrolled->value)
                ->where('es.status', '!=', EnrollmentSubjectStatus::Dropped->value)
                ->whereRaw("UPPER(REPLACE(sub.code, ' ', '')) LIKE ?", [$prefix.'%']);
        };
    }

    /**
     * @param  list<int>  $termIds
     */
    private function passedNstp2(array $termIds): \Closure
    {
        $passing = array_values(array_map(
            static fn (GradeMark $mark): string => $mark->value,
            array_filter(GradeMark::cases(), static fn (GradeMark $mark): bool => $mark->isPassing()),
        ));

        return function (QueryBuilder $sub) use ($termIds, $passing): void {
            $sub->selectRaw('1')
                ->from('academic_grades as ag')
                ->join('subjects as sub', 'sub.id', '=', 'ag.subject_id')
                ->whereColumn('ag.student_id', 'student_profiles.id')
                ->whereIn('ag.academic_term_id', $termIds)
                ->where('ag.status', GradeStatus::Locked->value)
                ->whereIn('ag.mark', $passing)
                ->whereRaw("UPPER(REPLACE(sub.code, ' ', '')) LIKE 'NSTP2%'");
        };
    }

    /**
     * @param  Collection<int, StudentProfile>  $students
     * @return list<array<string, int|string>>
     */
    private function enrollmentListRows($students, AcademicTerm $term, int $offset): array
    {
        $subjects = $this->subjectsByStudent(array_values($students->map(fn (StudentProfile $student): int => $student->id)->all()), $term);
        $rows = [];

        foreach ($students as $index => $student) {
            $name = $this->nameParts($student->user);
            $program = ChedProgramName::split($student->program->name);
            $taken = $subjects[$student->id] ?? [];

            $rows[] = [
                'no' => $offset + $index + 1,
                'program' => $program['program'],
                'major' => $program['major'],
                'student_number' => $student->student_number,
                'first_name' => $name['first'],
                'middle_name' => $name['middle'],
                'surname' => $name['last'],
                'name_extension' => $name['suffix'],
                'citizenship' => (string) $student->citizenship,
                'gender' => $student->gender?->letter() ?? '',
                'year_level' => $student->year_level,
                'subjects' => implode(', ', array_map(
                    fn (array $subject): string => $subject['title'].' ('.$this->units($subject['units']).')',
                    $taken,
                )),
                'units' => $this->units(array_sum(array_column($taken, 'units'))),
                'remarks' => '',
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, StudentProfile>  $students
     * @return list<array<string, int|string>>
     */
    private function nstpRows($students, int $offset): array
    {
        $rows = [];

        foreach ($students as $index => $student) {
            $name = $this->nameParts($student->user);
            $splitAddress = filled($student->street_barangay) || filled($student->town_city) || filled($student->province);

            $rows[] = [
                'no' => $offset + $index + 1,
                'serial_no' => '',
                'surname' => $name['last'],
                'first_name' => $name['first'],
                'middle_name' => $name['middle'],
                'course' => $this->fullProgramName($student->program->name),
                'gender' => $student->gender?->word() ?? '',
                'birthdate' => $student->birth_date?->format('m/d/Y') ?? '',
                'street_barangay' => $splitAddress ? (string) $student->street_barangay : (string) $student->address,
                'town_city' => (string) $student->town_city,
                'province' => (string) $student->province,
                'contact_number' => (string) $student->contact_number,
                'email' => $student->user->email,
            ];
        }

        return $rows;
    }

    /** The NSTP forms write the course as one text: the program in full, with its major. */
    private function fullProgramName(string $stored): string
    {
        $name = ChedProgramName::split($stored);

        return $name['major'] === '' ? $name['program'] : $name['program'].' major in '.$name['major'];
    }

    /**
     * @return array{first: string, middle: string, last: string, suffix: string}
     */
    private function nameParts(User $user): array
    {
        $first = trim((string) $user->first_name);
        $last = trim((string) $user->last_name);
        $middle = trim((string) $user->middle_initial);
        $suffix = trim((string) $user->suffix);

        if ($first === '' || $last === '') {
            $split = PersonName::split($user->name);
            $first = $first !== '' ? $first : $split['first_name'];
            $last = $last !== '' ? $last : $split['last_name'];
            $middle = $middle !== '' ? $middle : (string) $split['middle_initial'];
            $suffix = $suffix !== '' ? $suffix : (string) $split['suffix'];
        }

        // The system stores a middle initial, not the whole middle name.
        if (mb_strlen(rtrim($middle, '.')) === 1) {
            $middle = rtrim($middle, '.').'.';
        }

        return ['first' => $first, 'middle' => $middle, 'last' => $last, 'suffix' => $suffix];
    }

    /**
     * The not-dropped subjects of each student's enrolled enrollment in the term, by subject code.
     *
     * @param  list<int>  $studentIds
     * @return array<int, list<array{title: string, units: float}>>
     */
    private function subjectsByStudent(array $studentIds, AcademicTerm $term): array
    {
        $byStudent = [];

        foreach (array_chunk($studentIds, self::IDS_PER_QUERY) as $chunk) {
            $subjects = DB::table('enrollments as e')
                ->join('enrollment_subjects as es', 'es.enrollment_id', '=', 'e.id')
                ->join('sections as sec', 'sec.id', '=', 'es.section_id')
                ->join('subjects as sub', 'sub.id', '=', 'sec.subject_id')
                ->where('e.academic_term_id', $term->id)
                ->where('e.status', EnrollmentStatus::Enrolled->value)
                ->where('es.status', '!=', EnrollmentSubjectStatus::Dropped->value)
                ->whereIn('e.student_id', $chunk)
                ->orderBy('sub.code')
                ->get(['e.student_id', 'sub.title', 'sub.units']);

            foreach ($subjects as $subject) {
                $byStudent[(int) $subject->student_id][] = ['title' => (string) $subject->title, 'units' => (float) $subject->units];
            }
        }

        return $byStudent;
    }

    private function units(float|int|string $units): string
    {
        return rtrim(rtrim(number_format((float) $units, 2, '.', ''), '0'), '.');
    }
}
