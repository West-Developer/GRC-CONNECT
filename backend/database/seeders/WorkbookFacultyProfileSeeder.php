<?php

namespace Database\Seeders;

use App\Domain\Faculty\SpecializationProficiency;
use App\Domain\Identity\FacultyEmploymentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\WorkbookFacultyProfileName;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\FacultyAvailability;
use App\Models\FacultyCurriculumSubjectPreference;
use App\Models\FacultySpecialization;
use App\Models\FacultyTeachingHistory;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use DateTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

/**
 * Local/test-only faculty profiles derived from the deterministic schedule
 * reference extract of the supplied 2024–2029 curriculum workbooks. The
 * source is advisory teaching evidence, not a production HR import.
 */
final class WorkbookFacultyProfileSeeder extends Seeder
{
    private const CSV_PATH = __DIR__.'/data/curriculum-2024-2029-schedule-references.csv';

    private const PROFESSOR_DIRECTORY_PATH = __DIR__.'/../../../Subject And Prerequisuite/Professor_Department_List.md';

    private const PASSWORD = 'password';

    private const FULL_TIME_PREFERENCE_THRESHOLD = 6;

    /** @var array<string, list<array{name: string, reason: string}>> */
    private const INACTIVE_PROFILES = [
        'ccs' => [
            ['name' => 'Marian S. Villanueva', 'reason' => 'resigned'],
            ['name' => 'Jerome D. Aguilar', 'reason' => 'retired'],
            ['name' => 'Liza P. Navarro', 'reason' => 'contract_ended'],
            ['name' => 'Roberto K. Domingo', 'reason' => 'resigned'],
        ],
        'coe' => [
            ['name' => 'Imelda R. Valdez', 'reason' => 'retired'],
            ['name' => 'Noel P. Salvador', 'reason' => 'contract_ended'],
            ['name' => 'Rosalie T. Aquino', 'reason' => 'resigned'],
            ['name' => 'Danilo M. Cruz', 'reason' => 'retired'],
        ],
        'cbae' => [
            ['name' => 'Oscar M. Fernandez', 'reason' => 'resigned'],
            ['name' => 'Celeste D. Aragon', 'reason' => 'contract_ended'],
            ['name' => 'Victor L. Ramos', 'reason' => 'retired'],
            ['name' => 'Maricel G. Santos', 'reason' => 'resigned'],
        ],
        'coa' => [
            ['name' => 'Teresita L. Cruz', 'reason' => 'retired'],
            ['name' => 'Ramon C. de Leon', 'reason' => 'resigned'],
            ['name' => 'Sandra M. Bautista', 'reason' => 'contract_ended'],
            ['name' => 'Eduardo F. Mendoza', 'reason' => 'resigned'],
        ],
    ];

    /** @var list<string> */
    private const LEGACY_PLACEHOLDER_NAMES = [
        'Adrian M. Navarro', 'Bianca S. Mercado', 'Carlos D. Ramos',
        'Diana L. Santos', 'Elena P. Cruz', 'Felix R. Mendoza',
        'Grace T. Villanueva', 'Hector A. Aguilar', 'Iris N. Bautista',
        'Jonas C. Garcia', 'Kara F. Domingo', 'Lorenzo V. Reyes',
    ];

    /** @var array<string, Subject> */
    private array $subjectsByCollegeAndCode = [];

    /** @var array<string, list<Curriculum>> */
    private array $currentAndPreviousCurriculaByProgram = [];

    /** @var array<string, true> */
    private array $curriculumSubjectSemesterKeys = [];

    /** @var array<string, list<array{curriculum_id: int, semester: string, subject_ids: list<int>}>> */
    private array $preferenceSlotsByCollege = [];

    /** @var array<string, list<int>> */
    private array $curriculumSubjectIdsByCollege = [];

    /** @var array<int, string> */
    private array $subjectCollegeById = [];

    /** @var list<int> */
    private array $unscopedSpecializationSubjectIds = [];

    private ?string $localPasswordHash = null;

    public function __construct(
        private readonly ?string $csvPathOverride = null,
        private readonly ?string $professorDirectoryPathOverride = null,
    ) {}

    public function run(): void
    {
        $this->guardEnvironment();
        $rows = $this->readRows($this->csvPathOverride ?? self::CSV_PATH);
        $profiles = $this->profileRows($rows);
        $professorDirectoryPath = $this->professorDirectoryPath();
        $professorDirectoryEntries = $professorDirectoryPath === null
            ? []
            : $this->readProfessorDirectory($professorDirectoryPath);
        $this->loadReferenceData();

        $resolvedProfessorDirectory = DB::transaction(function () use ($profiles, $professorDirectoryEntries): array {
            FacultyCurriculumSubjectPreference::query()->where('origin', 'workbook_seeded')->delete();
            FacultyAvailability::query()->where('origin', 'workbook_seeded')->delete();
            FacultySpecialization::query()->where('source', 'seeded')->delete();

            /** @var array<string, User> $usersByProfile */
            $usersByProfile = [];
            foreach ($profiles as $profileKey => $profile) {
                $name = WorkbookFacultyProfileName::displayName($profile['aliases']);
                $college = $profile['college'];
                $email = $this->emailFor($college, $name, $profileKey);
                $usersByProfile[$profileKey] = User::updateOrCreate(
                    ['email' => $email],
                    [
                        'name' => $name,
                        'password' => $this->localPasswordHash(),
                        'role' => UserRole::Faculty,
                        'college' => $college,
                        'status' => UserStatus::Active,
                    ],
                );
            }

            $resolvedProfessorDirectory = $this->synchronizeProfessorDirectory(
                $professorDirectoryEntries,
            );
            $this->deactivateLegacyLocalFaculty(array_values($usersByProfile));
            $this->replaceWorkbookEvidence($profiles, $usersByProfile);
            $this->ensureLocalFacultyAccounts();
            $this->initializeEmploymentTypes();
            $this->seedInactiveFacultyProfiles();
            $this->activateAllFacultyAccounts();
            $this->seedCompleteFacultyProfiles();

            return $resolvedProfessorDirectory;
        });

        $this->writeAccountReport();
        if ($professorDirectoryPath !== null) {
            $this->writeProfessorDirectory(
                $professorDirectoryPath,
                $resolvedProfessorDirectory,
            );
        }
    }

    /**
     * @return list<array{number: ?int, name: string, email: ?string, department: string}>
     */
    private function readProfessorDirectory(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("WorkbookFacultyProfileSeeder could not find its professor directory at {$path}");
        }

        $entries = [];
        foreach (preg_split('/\R/u', File::get($path)) ?: [] as $line) {
            if (preg_match('/^\|\s*(\d+)\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*$/u', $line, $matches) === 1) {
                $number = (int) $matches[1];
                $name = trim($matches[2]);
                $email = null;
                $department = trim($matches[3]);
            } elseif (preg_match('/^\|\s*(.*?)\s*\|\s*([^|\s]+@grc\.(?:com|test))\s*\|\s*(.*?)\s*\|/iu', $line, $matches) === 1) {
                $number = null;
                $name = trim($matches[1]);
                $email = strtolower(trim($matches[2]));
                $department = trim($matches[3]);
            } else {
                continue;
            }
            if ($name === '' || $department === '') {
                continue;
            }
            $entries[] = [
                'number' => $number,
                'name' => $name,
                'email' => $email,
                'department' => $department,
            ];
        }

        return $entries;
    }

    private function professorDirectoryPath(): ?string
    {
        if ($this->professorDirectoryPathOverride !== null) {
            return $this->professorDirectoryPathOverride;
        }

        return app()->environment('local') && is_file(self::PROFESSOR_DIRECTORY_PATH)
            ? self::PROFESSOR_DIRECTORY_PATH
            : null;
    }

    /**
     * @param  list<array{number: ?int, name: string, email: ?string, department: string}>  $entries
     * @return list<array{name: string, email: string, department: string}>
     */
    private function synchronizeProfessorDirectory(array $entries): array
    {
        $resolvedEntries = [];
        foreach ($entries as $entry) {
            $college = CollegeCode::tryFrom(strtolower($entry['department']));
            $name = $this->professorDirectoryDisplayName($entry['name']);
            $email = $entry['email'] ?? $this->professorDirectoryEmail($college, $name, $entry);
            $professor = User::firstOrNew(['email' => $email]);
            $professor->fill([
                'name' => $name,
                'password' => $this->localPasswordHash(),
                'role' => UserRole::Faculty,
                'college' => $college,
                'status' => UserStatus::Active,
            ]);
            if ($professor->employment_type === null) {
                $professor->employment_type = FacultyEmploymentType::PartTime;
            }
            $professor->save();
            $resolvedEntries[] = [
                'name' => $professor->name,
                'email' => $professor->email,
                'department' => $entry['department'],
            ];
        }

        return $resolvedEntries;
    }

    private function professorDirectoryDisplayName(string $sourceName): string
    {
        $withoutHonorific = trim((string) preg_replace(
            '/^(?:mr|mrs|ms|dr|atty)\.?\s+/iu',
            '',
            $sourceName,
        ));
        $tokens = preg_split('/\s+/u', $withoutHonorific) ?: [];
        $firstToken = str_replace('.', '', $tokens[0] ?? '');

        if (count($tokens) >= 2 && mb_strlen($firstToken) > 2) {
            return $withoutHonorific;
        }

        return WorkbookFacultyProfileName::displayName([$sourceName]);
    }

    /**
     * @param  array{number: ?int, name: string, email: ?string, department: string}  $entry
     */
    private function professorDirectoryEmail(?CollegeCode $college, string $name, array $entry): string
    {
        $scope = $college?->value ?? 'coe';
        $clean = trim((string) preg_replace('/^(?:dr\.|coach\s+|atty\.)\s*/iu', '', $name));
        $cleanWithoutSuffix = trim((string) preg_replace('/\s+(?:jr\.?|sr\.?|ii|iii|iv|v)$/iu', '', $clean));
        $tokens = preg_split('/\s+/u', $cleanWithoutSuffix, -1, PREG_SPLIT_NO_EMPTY) ?: [$cleanWithoutSuffix];
        $first = strtolower((string) preg_replace('/[^a-z0-9]/iu', '', $tokens[0]));
        if (preg_match('/\b(dela\s+\w+|delos\s+\w+|de\s+\w+|san\s+\w+|[\w]+-[\w]+)$/iu', $cleanWithoutSuffix, $matchCompound)) {
            $last = strtolower((string) preg_replace('/[^a-z0-9]/iu', '', $matchCompound[0]));
        } else {
            $last = strtolower((string) preg_replace('/[^a-z0-9]/iu', '', (string) end($tokens)));
        }

        if (strtolower($name) === 'henry nieva corrales' && $scope === 'coe') {
            return 'henry.corales.coe@grc.com';
        }

        return "{$first}.{$last}.{$scope}@grc.com";
    }

    private function activateAllFacultyAccounts(): void
    {
        User::query()
            ->where('role', UserRole::Faculty->value)
            ->update(['status' => UserStatus::Active->value]);
    }

    /**
     * @param  list<array{name: string, email: string, department: string}>  $entries
     */
    private function writeProfessorDirectory(string $path, array $entries): void
    {
        $lines = [
            '# Professor and Department List',
            '',
            '| Professor Name | Email | Department |',
            '|---|---|---|',
        ];
        foreach ($entries as $entry) {
            $lines[] = sprintf(
                '| %s | %s | %s |',
                $entry['name'],
                $entry['email'],
                $entry['department'],
            );
        }
        $lines[] = '';
        $lines[] = '**Total professors:** '.count($entries);

        File::put($path, implode(PHP_EOL, $lines).PHP_EOL);
    }

    /** @return list<array<string, string>> */
    private function readRows(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("WorkbookFacultyProfileSeeder could not find its CSV at {$path}");
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $header = null;
        $rows = [];
        foreach ($file as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }
            if ($header === null) {
                $header = array_map(static fn (?string $value): string => trim((string) $value), $line);

                continue;
            }
            if (count($line) !== count($header)) {
                continue;
            }
            /** @var array<string, string> $row */
            $row = array_combine($header, $line);
            if (trim($row['professor_name'] ?? '') !== '') {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, array{college: CollegeCode, aliases: list<string>, rows: list<array<string, string>>}>
     */
    private function profileRows(array $rows): array
    {
        $profiles = [];
        foreach ($rows as $row) {
            $college = CollegeCode::tryFrom(strtolower(trim($row['college'] ?? '')));
            $alias = trim($row['professor_name'] ?? '');
            if ($college === null || $alias === '') {
                continue;
            }
            $key = $college->value.'|'.WorkbookFacultyProfileName::identityKey($alias);
            $profiles[$key] ??= ['college' => $college, 'aliases' => [], 'rows' => []];
            $profiles[$key]['aliases'][] = $alias;
            $profiles[$key]['rows'][] = $row;
        }

        ksort($profiles);

        return $profiles;
    }

    /**
     * @param  list<User>  $currentProfiles
     */
    private function deactivateLegacyLocalFaculty(array $currentProfiles): void
    {
        $currentIds = array_map(static fn (User $user): int => $user->id, $currentProfiles);
        User::query()
            ->where('role', UserRole::Faculty->value)
            ->where('email', 'like', 'faculty.%@grc.test')
            ->whereNotIn('id', $currentIds)
            ->where(function ($query): void {
                $query->where('email', 'faculty.seed@grc.test')
                    ->orWhereRaw("name not like '% %'");
            })
            ->update(['status' => UserStatus::Disabled->value]);
    }

    /**
     * Existing faculty records are identities already, but a local report and
     * repeatable demo need every account to have a safe local email and a
     * displayable full name. This never changes active/inactive status.
     */
    private function ensureLocalFacultyAccounts(): void
    {
        User::query()
            ->where('role', UserRole::Faculty->value)
            ->orderBy('id')
            ->each(function (User $faculty): void {
                $changes = [];
                if (! str_ends_with(strtolower($faculty->email), '@grc.test') && ! str_ends_with(strtolower($faculty->email), '@grc.com')) {
                    $college = $faculty->college?->value ?? 'unassigned';
                    $changes['email'] = "faculty.{$college}.legacy-{$faculty->id}@grc.test";
                }
                if (! str_contains(trim($faculty->name), ' ')
                    || preg_match('/\b(demo|testing)\b/iu', $faculty->name) === 1) {
                    $changes['name'] = $this->localFullNameFor($faculty);
                }
                // This seeder is deliberately restricted to local/testing
                // environments. The shared local credential is part of the
                // generated account report so every listed professor can be
                // checked manually without a separate password reset.
                $changes['password'] = $this->localPasswordHash();
                if ($changes !== []) {
                    $faculty->update($changes);
                }
            });
    }

    /**
     * Null is the seed-only sentinel. Once the Program Chair chooses an
     * employment type it is never overwritten by later workbook reseeds.
     */
    private function initializeEmploymentTypes(): void
    {
        $preferenceCounts = FacultyCurriculumSubjectPreference::query()
            ->selectRaw('professor_id, count(distinct subject_id) as preference_count')
            ->groupBy('professor_id')
            ->pluck('preference_count', 'professor_id');

        User::query()
            ->where('role', UserRole::Faculty->value)
            ->whereNull('employment_type')
            ->orderBy('id')
            ->each(function (User $faculty) use ($preferenceCounts): void {
                $count = (int) ($preferenceCounts[$faculty->id] ?? 0);
                $faculty->update([
                    'employment_type' => $count >= self::FULL_TIME_PREFERENCE_THRESHOLD
                        ? FacultyEmploymentType::FullTime
                        : FacultyEmploymentType::PartTime,
                ]);
            });
    }

    private function seedInactiveFacultyProfiles(): void
    {
        foreach (self::INACTIVE_PROFILES as $collegeValue => $profiles) {
            $college = CollegeCode::from($collegeValue);
            foreach ($profiles as $index => $profile) {
                User::updateOrCreate(
                    ['email' => "inactive.{$collegeValue}.".($index + 1).'@grc.test'],
                    [
                        'name' => $profile['name'],
                        'password' => $this->localPasswordHash(),
                        'role' => UserRole::Faculty,
                        'college' => $college,
                        'employment_type' => FacultyEmploymentType::PartTime,
                        'status' => UserStatus::Disabled,
                        'deactivation_reason' => $profile['reason'],
                    ],
                );
            }
        }
    }

    /**
     * @param  array<string, array{college: CollegeCode, aliases: list<string>, rows: list<array<string, string>>}>  $profiles
     * @param  array<string, User>  $usersByProfile
     */
    private function replaceWorkbookEvidence(array $profiles, array $usersByProfile): void
    {
        $profileIds = array_map(static fn (User $user): int => $user->id, array_values($usersByProfile));
        FacultyCurriculumSubjectPreference::query()
            ->whereIn('professor_id', $profileIds)
            ->where('origin', 'workbook_seeded')
            ->delete();
        FacultyTeachingHistory::query()
            ->whereIn('professor_id', $profileIds)
            ->where('source_kind', 'workbook_reference')
            ->delete();
        FacultyAvailability::query()
            ->whereIn('professor_id', $profileIds)
            ->where('origin', 'workbook_seeded')
            ->delete();

        $evidence = [];
        $availability = [];
        foreach ($profiles as $profileKey => $profile) {
            $professor = $usersByProfile[$profileKey];
            foreach ($profile['rows'] as $row) {
                $semester = trim($row['semester']);
                $subject = $this->subjectsByCollegeAndCode[
                    $this->subjectKey($profile['college']->value, $row['subject_code'])
                ] ?? null;
                if ($subject !== null) {
                    foreach ($this->matchingCurricula($row, $subject->id) as $curriculum) {
                        $key = implode(':', [$professor->id, $curriculum->id, $semester, $subject->id]);
                        $evidence[$key] ??= [
                            'professor_id' => $professor->id,
                            'curriculum_id' => $curriculum->id,
                            'semester' => $semester,
                            'subject_id' => $subject->id,
                            'subject_code' => $subject->code,
                            'raw_alias' => trim($row['professor_name']),
                            'evidence_count' => 0,
                        ];
                        $evidence[$key]['evidence_count']++;
                    }
                }

                $start = $this->normalizeTime($row['start_time'] ?? '');
                $end = $this->normalizeTime($row['end_time'] ?? '');
                if ($start === null || $end === null || $end <= $start) {
                    continue;
                }
                foreach ($this->days($row['day'] ?? '') as $day) {
                    $availability[$professor->id.':'.$day][] = [
                        'professor_id' => $professor->id,
                        'day_of_week' => $day,
                        'starts_at_time' => $start,
                        'ends_at_time' => $end,
                    ];
                }
            }
        }

        $this->persistEvidence($evidence);
        $this->persistAvailability($availability);
    }

    /** @return list<Curriculum> */
    private function matchingCurricula(array $row, int $subjectId): array
    {
        $semester = trim($row['semester']);
        $programKey = strtolower(trim($row['college'])).'|'.strtoupper(trim($row['program_code']));

        return array_values(array_filter(
            $this->currentAndPreviousCurriculaByProgram[$programKey] ?? [],
            fn (Curriculum $curriculum): bool => isset($this->curriculumSubjectSemesterKeys[
                $curriculum->id.':'.$subjectId.':'.$semester
            ]) || isset($this->curriculumSubjectSemesterKeys[
                $curriculum->id.':'.$subjectId.':1st|2nd'
            ]),
        ));
    }

    /** @param array<string, array{professor_id: int, curriculum_id: int, semester: string, subject_id: int, subject_code: string, raw_alias: string, evidence_count: int}> $evidence */
    private function persistEvidence(array $evidence): void
    {
        $byPreferenceList = [];
        foreach ($evidence as $row) {
            FacultyTeachingHistory::create([
                'professor_id' => $row['professor_id'],
                'curriculum_id' => $row['curriculum_id'],
                'subject_id' => $row['subject_id'],
                'semester' => $row['semester'],
                'source_kind' => 'workbook_reference',
                'source_workbook' => $row['semester'] === '1st'
                    ? '2024 - 2029 Curriculum 1st Semester.xlsx'
                    : '2024 - 2029 Curriculum 2nd Semester.xlsx',
                'raw_alias' => $row['raw_alias'],
                'evidence_count' => $row['evidence_count'],
            ]);
            $byPreferenceList[$row['professor_id'].':'.$row['curriculum_id'].':'.$row['semester']][] = $row;
        }

        foreach ($byPreferenceList as $rows) {
            usort($rows, static fn (array $left, array $right): int => [
                -$left['evidence_count'], $left['subject_code'], $left['subject_id'],
            ] <=> [
                -$right['evidence_count'], $right['subject_code'], $right['subject_id'],
            ]);
            foreach ($rows as $index => $row) {
                FacultyCurriculumSubjectPreference::create([
                    'professor_id' => $row['professor_id'],
                    'curriculum_id' => $row['curriculum_id'],
                    'subject_id' => $row['subject_id'],
                    'semester' => $row['semester'],
                    'rank' => $index + 1,
                    'origin' => 'workbook_seeded',
                ]);
            }
        }
    }

    /** @param array<string, list<array{professor_id: int, day_of_week: int, starts_at_time: string, ends_at_time: string}>> $availability */
    private function persistAvailability(array $availability): void
    {
        foreach ($availability as $windows) {
            usort($windows, static fn (array $left, array $right): int => [$left['starts_at_time'], $left['ends_at_time']] <=> [$right['starts_at_time'], $right['ends_at_time']]);
            $merged = [];
            foreach ($windows as $window) {
                $lastIndex = array_key_last($merged);
                if ($lastIndex !== null && $window['starts_at_time'] <= $merged[$lastIndex]['ends_at_time']) {
                    $merged[$lastIndex]['ends_at_time'] = max($merged[$lastIndex]['ends_at_time'], $window['ends_at_time']);

                    continue;
                }
                $merged[] = $window;
            }
            foreach ($merged as $window) {
                FacultyAvailability::create($window + ['origin' => 'workbook_seeded']);
            }
        }
    }

    /**
     * Every local/test faculty identity receives a complete, reproducible
     * profile. Workbook history stays intact as advisory evidence; profile
     * choices are regenerated from the stable email hash so reseeds are
     * idempotent while declared records remain untouched.
     */
    private function seedCompleteFacultyProfiles(): void
    {
        $faculty = User::query()
            ->where('role', UserRole::Faculty->value)
            ->orderBy('id')
            ->get(['id', 'email', 'college', 'employment_type']);
        $facultyIds = $faculty->pluck('id')->all();

        $availabilitySlotsByProfessor = FacultyAvailability::query()
            ->whereIn('professor_id', $facultyIds)
            ->get(['professor_id', 'day_of_week', 'starts_at_time'])
            ->groupBy('professor_id')
            ->map(static fn ($windows): array => $windows
                ->mapWithKeys(static fn (FacultyAvailability $window): array => [
                    $window->day_of_week.':'.$window->starts_at_time => true,
                ])
                ->all())
            ->all();
        $declaredPreferenceRows = FacultyCurriculumSubjectPreference::query()
            ->whereIn('professor_id', $facultyIds)
            ->get(['professor_id', 'curriculum_id', 'semester', 'subject_id', 'rank'])
            ->groupBy(static fn (FacultyCurriculumSubjectPreference $preference): string => implode(':', [
                $preference->professor_id,
                $preference->curriculum_id,
                $preference->semester,
            ]));
        $declaredSpecializationSubjectIds = FacultySpecialization::query()
            ->whereIn('professor_id', $facultyIds)
            ->get(['professor_id', 'subject_id'])
            ->groupBy('professor_id')
            ->map(static fn ($specializations): array => array_fill_keys(
                $specializations->pluck('subject_id')->all(),
                true,
            ))
            ->all();
        $historySubjectIdsByProfessor = FacultyTeachingHistory::query()
            ->whereIn('professor_id', $facultyIds)
            ->orderByDesc('evidence_count')
            ->orderBy('subject_id')
            ->get(['professor_id', 'subject_id'])
            ->groupBy('professor_id')
            ->map(static fn ($history): array => array_values(array_unique($history->pluck('subject_id')->all())))
            ->all();

        $timestamp = now()->toDateTimeString();
        $availabilityRows = [];
        $preferenceRows = [];
        $specializationRows = [];
        foreach ($faculty as $professor) {
            $hash = $this->profileSeedHash($professor->email);
            $occupiedAvailabilitySlots = $availabilitySlotsByProfessor[$professor->id] ?? [];
            foreach ($this->deterministicAvailabilityWindows($professor, $hash) as $window) {
                $slot = $window['day_of_week'].':'.$window['starts_at_time'];
                if (isset($occupiedAvailabilitySlots[$slot])) {
                    continue;
                }
                $availabilityRows[] = $window + [
                    'professor_id' => $professor->id,
                    'origin' => 'workbook_seeded',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $occupiedAvailabilitySlots[$slot] = true;
            }

            $existingSpecializations = $declaredSpecializationSubjectIds[$professor->id] ?? [];
            foreach ($this->deterministicSpecializations(
                $professor,
                $hash,
                $historySubjectIdsByProfessor[$professor->id] ?? [],
                $existingSpecializations,
            ) as $specialization) {
                $specializationRows[] = $specialization + [
                    'professor_id' => $professor->id,
                    'source' => 'seeded',
                    'notes' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            if ($professor->college === null) {
                continue;
            }
            foreach ($this->preferenceSlotsByCollege[$professor->college->value] ?? [] as $slot) {
                $preferenceKey = implode(':', [$professor->id, $slot['curriculum_id'], $slot['semester']]);
                $existingPreferences = $declaredPreferenceRows[$preferenceKey] ?? collect();
                $occupiedSubjectIds = array_fill_keys($existingPreferences->pluck('subject_id')->all(), true);
                $occupiedRanks = array_fill_keys($existingPreferences->pluck('rank')->all(), true);
                $candidateSubjectIds = array_values(array_filter(
                    $slot['subject_ids'],
                    static fn (int $subjectId): bool => ! isset($occupiedSubjectIds[$subjectId]),
                ));
                $count = min(count($candidateSubjectIds), 5 + ($hash % 4));
                $rank = 1;
                foreach ($this->deterministicSubjectIds($candidateSubjectIds, $count, $hash) as $subjectId) {
                    while (isset($occupiedRanks[$rank])) {
                        $rank++;
                    }
                    $preferenceRows[] = [
                        'professor_id' => $professor->id,
                        'curriculum_id' => $slot['curriculum_id'],
                        'semester' => $slot['semester'],
                        'subject_id' => $subjectId,
                        'rank' => $rank,
                        'origin' => 'workbook_seeded',
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                    $occupiedRanks[$rank] = true;
                    $rank++;
                }
            }
        }

        foreach (array_chunk($availabilityRows, 500) as $rows) {
            DB::table('faculty_availabilities')->insert($rows);
        }
        foreach (array_chunk($preferenceRows, 500) as $rows) {
            DB::table('faculty_curriculum_subject_preferences')->insert($rows);
        }
        foreach (array_chunk($specializationRows, 500) as $rows) {
            DB::table('faculty_specializations')->insert($rows);
        }
    }

    /** @return list<array{day_of_week: int, starts_at_time: string, ends_at_time: string}> */
    private function deterministicAvailabilityWindows(User $professor, int $hash): array
    {
        if ($professor->employment_type === FacultyEmploymentType::FullTime) {
            return array_map(static fn (int $day): array => [
                'day_of_week' => $day,
                'starts_at_time' => '08:00:00',
                'ends_at_time' => '17:00:00',
            ], range(1, 5));
        }

        $days = $this->deterministicSubjectIds(range(1, 6), 3, $hash);

        return array_map(static fn (int $day, int $index): array => [
            'day_of_week' => $day,
            'starts_at_time' => sprintf('%02d:00:00', 8 + (($hash >> ($index * 3)) % 6)),
            'ends_at_time' => sprintf('%02d:00:00', 12 + (($hash >> ($index * 3)) % 6)),
        ], $days, array_keys($days));
    }

    /**
     * @param  list<int>  $historySubjectIds
     * @param  array<int, true>  $existingSubjectIds
     * @return list<array{subject_id: int, proficiency: string}>
     */
    private function deterministicSpecializations(
        User $professor,
        int $hash,
        array $historySubjectIds,
        array $existingSubjectIds,
    ): array {
        $desiredCount = 4 + ($hash % 7);
        if ($professor->college === null) {
            return array_map(static fn (int $subjectId): array => [
                'subject_id' => $subjectId,
                'proficiency' => SpecializationProficiency::Secondary->value,
            ], $this->deterministicSubjectIds(
                array_values(array_filter(
                    $this->unscopedSpecializationSubjectIds,
                    static fn (int $subjectId): bool => ! isset($existingSubjectIds[$subjectId]),
                )),
                $desiredCount,
                $hash,
            ));
        }

        $college = $professor->college->value;
        $primarySubjectIds = array_values(array_filter(
            $historySubjectIds,
            fn (int $subjectId): bool => ($this->subjectCollegeById[$subjectId] ?? null) === $college
                && ! isset($existingSubjectIds[$subjectId]),
        ));
        $primarySubjectIds = $this->deterministicSubjectIds($primarySubjectIds, $desiredCount, $hash);
        $secondarySubjectIds = $this->deterministicSubjectIds(
            array_values(array_filter(
                $this->curriculumSubjectIdsByCollege[$college] ?? [],
                static fn (int $subjectId): bool => ! isset($existingSubjectIds[$subjectId])
                    && ! in_array($subjectId, $primarySubjectIds, true),
            )),
            $desiredCount - count($primarySubjectIds),
            $hash,
        );

        return [
            ...array_map(static fn (int $subjectId): array => [
                'subject_id' => $subjectId,
                'proficiency' => SpecializationProficiency::Primary->value,
            ], $primarySubjectIds),
            ...array_map(static fn (int $subjectId): array => [
                'subject_id' => $subjectId,
                'proficiency' => SpecializationProficiency::Secondary->value,
            ], $secondarySubjectIds),
        ];
    }

    /**
     * @param  list<int>  $subjectIds
     * @return list<int>
     */
    private function deterministicSubjectIds(array $subjectIds, int $count, int $hash): array
    {
        $subjectIds = array_values(array_unique($subjectIds));
        sort($subjectIds);
        if ($count === 0 || $subjectIds === []) {
            return [];
        }

        $count = min($count, count($subjectIds));
        $offset = $hash % count($subjectIds);
        $selected = [];
        for ($index = 0; $index < $count; $index++) {
            $selected[] = $subjectIds[($offset + $index) % count($subjectIds)];
        }

        return $selected;
    }

    private function profileSeedHash(string $email): int
    {
        return (int) sprintf('%u', crc32($email));
    }

    /**
     * Preload the subject, curriculum, and placement data used by every
     * workbook row. This keeps the local-only seed deterministic without
     * issuing hundreds of repeated catalog queries.
     */
    private function loadReferenceData(): void
    {
        $this->subjectsByCollegeAndCode = [];
        $this->subjectCollegeById = [];
        $this->curriculumSubjectIdsByCollege = [];
        $subjects = Subject::query()->orderBy('college')->orderBy('code')->orderBy('id')->get(['id', 'college', 'code']);
        $duplicateCodeCounts = $subjects->groupBy('code')->map->count();
        foreach ($subjects as $subject) {
            if ($subject->college === null) {
                continue;
            }
            $this->subjectCollegeById[$subject->id] = $subject->college->value;
            $this->subjectsByCollegeAndCode[
                $this->subjectKey($subject->college->value, $subject->code)
            ] = $subject;
            if (preg_match('/^(?:NSTP|PATHFIT|PE)/iu', $subject->code) === 1
                || ($duplicateCodeCounts[$subject->code] ?? 0) > 1) {
                $this->unscopedSpecializationSubjectIds[] = $subject->id;
            }
        }

        $curricula = Curriculum::query()
            ->with('program')
            ->whereIn('status', ['active', 'archived'])
            ->orderByDesc('effective_start_year')
            ->get();
        $this->currentAndPreviousCurriculaByProgram = [];
        foreach ($curricula->groupBy('program_id') as $programCurricula) {
            $selected = $programCurricula->take(2)->values();
            $program = $selected->first()?->program;
            if ($program === null || $program->college === null) {
                continue;
            }
            $this->currentAndPreviousCurriculaByProgram[
                $program->college->value.'|'.strtoupper($program->code)
            ] = $selected->all();
        }

        $curriculumIds = $curricula->pluck('id');
        $this->curriculumSubjectSemesterKeys = [];
        /** @var array<string, array<string, array{curriculum_id: int, semester: string, subject_ids: list<int>}>> */
        $preferenceSlotsByCollegeAndKey = [];
        foreach (CurriculumSubject::query()
            ->whereIn('curriculum_id', $curriculumIds)
            ->orderBy('curriculum_id')
            ->orderBy('subject_id')
            ->get(['curriculum_id', 'subject_id', 'semester']) as $placement) {
            $this->curriculumSubjectSemesterKeys[
                $placement->curriculum_id.':'.$placement->subject_id.':'.$placement->semester
            ] = true;
            $curriculum = $curricula->firstWhere('id', $placement->curriculum_id);
            $college = $curriculum?->program?->college?->value;
            if ($college === null || ($this->subjectCollegeById[$placement->subject_id] ?? null) !== $college) {
                continue;
            }
            foreach (explode('|', $placement->semester) as $semester) {
                $slotKey = $placement->curriculum_id.':'.$semester;
                $slot = $preferenceSlotsByCollegeAndKey[$college][$slotKey] ?? [
                    'curriculum_id' => $placement->curriculum_id,
                    'semester' => $semester,
                    'subject_ids' => [],
                ];
                $slot['subject_ids'][] = $placement->subject_id;
                $preferenceSlotsByCollegeAndKey[$college][$slotKey] = $slot;
                $this->curriculumSubjectIdsByCollege[$college][] = $placement->subject_id;
            }
        }

        $this->preferenceSlotsByCollege = [];
        foreach ($preferenceSlotsByCollegeAndKey as $college => $slots) {
            $this->preferenceSlotsByCollege[$college] = array_values($slots);
        }
        foreach ($this->curriculumSubjectIdsByCollege as $college => $subjectIds) {
            $this->curriculumSubjectIdsByCollege[$college] = array_values(array_unique($subjectIds));
        }
        $this->unscopedSpecializationSubjectIds = array_values(array_unique($this->unscopedSpecializationSubjectIds));

    }

    private function subjectKey(string $college, string $code): string
    {
        return strtolower(trim($college)).'|'.strtoupper(trim($code));
    }

    /** @return list<int> */
    private function days(string $raw): array
    {
        $value = strtolower(trim($raw));
        $known = [
            'monday' => 1, 'mon' => 1, 'm' => 1,
            'tuesday' => 2, 'tue' => 2, 't' => 2,
            'wednesday' => 3, 'wed' => 3, 'w' => 3,
            'thursday' => 4, 'thu' => 4, 'th' => 4,
            'friday' => 5, 'fri' => 5, 'f' => 5,
            'saturday' => 6, 'sat' => 6,
        ];
        if (isset($known[$value])) {
            return [$known[$value]];
        }
        preg_match_all('/th|sat|m|t|w|f/u', str_replace(['/', ',', ' '], '', $value), $matches);

        return array_values(array_unique(array_filter(array_map(static fn (string $token): ?int => $known[$token] ?? null, $matches[0] ?? []))));
    }

    private function normalizeTime(string $raw): ?string
    {
        $value = strtoupper(trim($raw));
        $value = preg_replace('/^[=\-]+/', '', $value) ?? $value;
        $value = preg_replace('/([AP])\.?\s*M\.?/', '$1M', $value) ?? $value;
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        foreach (['g:iA', 'H:i', 'G:i'] as $format) {
            $parsed = DateTime::createFromFormat('!'.$format, $value);
            if ($parsed !== false) {
                return $parsed->format('H:i:s');
            }
        }

        return null;
    }

    private function emailFor(CollegeCode $college, string $name, string $profileKey): string
    {
        return 'faculty.'.$college->value.'.'.Str::slug($name).'.'.substr(sha1($profileKey), 0, 6).'@grc.test';
    }

    private function localPasswordHash(): string
    {
        return $this->localPasswordHash ??= Hash::make(self::PASSWORD);
    }

    private function localFullNameFor(User $faculty): string
    {
        if (preg_match('/\b(demo|testing)\b/iu', $faculty->name) === 1) {
            return self::LEGACY_PLACEHOLDER_NAMES[
                $faculty->id % count(self::LEGACY_PLACEHOLDER_NAMES)
            ];
        }

        return WorkbookFacultyProfileName::displayName([$faculty->name]);
    }

    private function writeAccountReport(): void
    {
        $lines = [
            '# Local professor accounts',
            '',
            'Generated from local faculty accounts and the 2024–2029 workbook reference extract. Shared password: `password`.',
        ];
        $faculty = User::query()
            ->where('role', UserRole::Faculty->value)
            ->orderBy('college')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $facultyIds = $faculty->pluck('id');
        $preferences = FacultyCurriculumSubjectPreference::query()
            ->with('subject')
            ->whereIn('professor_id', $facultyIds)
            ->orderBy('semester')
            ->orderBy('rank')
            ->get()
            ->groupBy('professor_id');
        $availability = FacultyAvailability::query()
            ->whereIn('professor_id', $facultyIds)
            ->orderBy('day_of_week')
            ->orderBy('starts_at_time')
            ->get()
            ->groupBy('professor_id');
        $history = FacultyTeachingHistory::query()
            ->with('subject')
            ->whereIn('professor_id', $facultyIds)
            ->orderByDesc('evidence_count')
            ->get()
            ->groupBy('professor_id');
        $currentTerm = AcademicTerm::query()->where('status', 'semester_ongoing')->first();
        $assignedUnits = $currentTerm === null
            ? collect()
            : Section::query()
                ->with('subject')
                ->where('academic_term_id', $currentTerm->id)
                ->whereIn('professor_id', $facultyIds)
                ->get()
                ->groupBy('professor_id')
                ->map(static fn ($sections): float => (float) $sections->sum(static fn (Section $section): float => (float) $section->subject->units));

        $facultyByCollege = $faculty->groupBy(
            static fn (User $user): string => strtoupper($user->college?->value ?? 'unassigned'),
        );
        foreach ($facultyByCollege as $college => $collegeFaculty) {
            $lines[] = '';
            $lines[] = '## '.$college;
            $lines[] = '';
            $lines[] = '| Professor | Email | Account status | Employment type | Planning reference | Desired subjects | Desired availability | Teaching history | Current assigned units |';
            $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- |';
            foreach ($collegeFaculty as $user) {
                $codes = ['1st' => [], '2nd' => []];
                foreach ($preferences->get($user->id, collect()) as $preference) {
                    $codes[$preference->semester] ??= [];
                    $codes[$preference->semester][] = $preference->subject->code;
                }
                $desiredSubjects = implode(', ', array_filter([
                    $codes['1st'] === [] ? null : '1st: '.implode(', ', array_unique($codes['1st'])),
                    $codes['2nd'] === [] ? null : '2nd: '.implode(', ', array_unique($codes['2nd'])),
                ]));
                $desiredAvailability = $availability->get($user->id, collect())
                    ->map(fn (FacultyAvailability $window): string => self::dayLabel($window->day_of_week).' '.$window->starts_at_time.'-'.$window->ends_at_time)
                    ->implode('; ');
                $teachingHistory = $history->get($user->id, collect())
                    ->map(fn (FacultyTeachingHistory $entry): string => $entry->subject->code.' ×'.$entry->evidence_count)
                    ->implode(', ');
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                    $user->name,
                    $user->email,
                    $user->status === UserStatus::Active ? 'Active' : 'Inactive',
                    $user->employment_type?->label() ?? 'Part-time',
                    $user->employment_type?->planningUnitReference() === null ? '—' : $user->employment_type->planningUnitReference().' units',
                    $desiredSubjects ?: '—',
                    $desiredAvailability ?: '—',
                    $teachingHistory ?: '—',
                    ($assignedUnits[$user->id] ?? 0).' units',
                );
            }
        }

        $directory = storage_path('app/local-reports');
        File::ensureDirectoryExists($directory);
        File::put($directory.'/professor-accounts.md', implode(PHP_EOL, $lines).PHP_EOL);
    }

    private static function dayLabel(int $day): string
    {
        return [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'][$day] ?? 'Unknown';
    }

    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('WorkbookFacultyProfileSeeder may only run locally or in testing.');
        }
    }
}
