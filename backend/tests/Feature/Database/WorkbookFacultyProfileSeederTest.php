<?php

namespace Tests\Feature\Database;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\FacultyAvailability;
use App\Models\FacultyCurriculumSubjectPreference;
use App\Models\FacultySpecialization;
use App\Models\FacultyTeachingHistory;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\GrcCurriculumSeeder;
use Database\Seeders\GrcSubjectCatalogSeeder;
use Database\Seeders\ProgramSeeder;
use Database\Seeders\WorkbookFacultyProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WorkbookFacultyProfileSeederTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_CSV = <<<'CSV'
college,program_code,year_level,semester,subject_code,day,start_time,end_time,room,modality,professor_name,sched_id,notes
ccs,BSIT,1,1st,IT101,M,08:00,10:00,3A,F2F,MR. H. CORRALES,101,
ccs,BSIT,1,2nd,IT201,T,13:00,15:00,3B,F2F,Henry N. Corrales,102,
CSV;

    public function test_it_creates_full_name_local_profiles_with_reusable_preferences_history_and_source_availability(): void
    {
        $this->seedReferenceCatalog();
        User::create([
            'name' => 'CORRALES',
            'email' => 'faculty.ccs.corrales@grc.test',
            'password' => 'previous-local-password',
            'role' => UserRole::Faculty,
            'college' => CollegeCode::Ccs,
            'status' => UserStatus::Active,
        ]);
        $placeholderFaculty = User::create([
            'name' => 'Testing Faculty',
            'email' => 'faculty.seed@grc.test',
            'password' => 'previous-local-password',
            'role' => UserRole::Faculty,
            'college' => CollegeCode::Ccs,
            'status' => UserStatus::Disabled,
        ]);
        $fixturePath = $this->writeFixture();

        try {
            (new WorkbookFacultyProfileSeeder($fixturePath))->run();

            $professor = User::query()->where('name', 'Henry N. Corrales')->sole();
            $this->assertTrue(Hash::check('password', $professor->password));
            $legacyFaculty = User::query()->where('email', 'faculty.ccs.corrales@grc.test')->sole();
            $this->assertSame(UserStatus::Active, $legacyFaculty->status);
            $this->assertTrue(Hash::check('password', $legacyFaculty->password));
            $placeholderFaculty->refresh();
            $this->assertDoesNotMatchRegularExpression('/\b(demo|testing)\b/iu', $placeholderFaculty->name);
            $this->assertTrue(Hash::check('password', $placeholderFaculty->password));
            $this->assertSame(8, FacultyCurriculumSubjectPreference::query()->where('professor_id', $professor->id)->count());
            $this->assertSame(2, FacultyTeachingHistory::query()->where('professor_id', $professor->id)->count());
            $this->assertGreaterThan(0, FacultyAvailability::query()
                ->where('professor_id', $professor->id)
                ->where('origin', 'workbook_seeded')
                ->count());
            $this->assertSame(0, FacultyAvailability::query()->where('day_of_week', 7)->count());

            (new WorkbookFacultyProfileSeeder($fixturePath))->run();
            $this->assertSame(1, User::query()->where('email', $professor->email)->count());
            $this->assertSame(8, FacultyCurriculumSubjectPreference::query()->where('professor_id', $professor->id)->count());
            $this->assertSame(2, FacultyTeachingHistory::query()->where('professor_id', $professor->id)->count());
        } finally {
            unlink($fixturePath);
        }
    }

    public function test_it_retains_the_real_workbook_evidence_derived_preference_after_completing_profiles(): void
    {
        $this->seedReferenceCatalog();
        $fixturePath = $this->writeFixture();

        try {
            (new WorkbookFacultyProfileSeeder($fixturePath))->run();

            $professor = User::query()->where('name', 'Henry N. Corrales')->sole();
            $curriculum = Curriculum::query()
                ->whereHas('program', fn ($query) => $query->where('college', CollegeCode::Ccs->value))
                ->sole();
            $subject = Subject::query()->where('code', 'IT101')->where('college', CollegeCode::Ccs->value)->sole();

            // The fixture's only 1st-semester evidence for this professor is
            // IT101, so the real evidence-derived preference must land at
            // rank 1 and survive `seedCompleteFacultyProfiles()`'s gap-fill
            // pass untouched — it must not be wiped and replaced by an
            // arbitrary hash-derived subject/rank.
            $this->assertDatabaseHas('faculty_curriculum_subject_preferences', [
                'professor_id' => $professor->id,
                'curriculum_id' => $curriculum->id,
                'semester' => '1st',
                'subject_id' => $subject->id,
                'rank' => 1,
                'origin' => 'workbook_seeded',
            ]);
        } finally {
            @unlink($fixturePath);
        }
    }

    public function test_it_assigns_a_local_employment_type_and_creates_named_active_faculty_accounts(): void
    {
        $this->seedReferenceCatalog();
        $fixturePath = $this->writeFixture([
            ['ccs', 'BSIT', '1st', 'CS101', 'Monday', '08:00 AM', '10:00 AM', 'Ada Santos'],
            ['ccs', 'BSIT', '1st', 'CS102', 'Tuesday', '08:00 AM', '10:00 AM', 'Ada Santos'],
            ['ccs', 'BSIT', '1st', 'CS103', 'Wednesday', '08:00 AM', '10:00 AM', 'Ada Santos'],
            ['ccs', 'BSIT', '2nd', 'CS201', 'Thursday', '08:00 AM', '10:00 AM', 'Ada Santos'],
            ['ccs', 'BSIT', '2nd', 'CS202', 'Friday', '08:00 AM', '10:00 AM', 'Ada Santos'],
            ['ccs', 'BSIT', '2nd', 'CS203', 'Saturday', '08:00 AM', '10:00 AM', 'Ada Santos'],
        ]);

        try {
            (new WorkbookFacultyProfileSeeder($fixturePath))->run();

            $this->assertDatabaseHas('users', [
                'name' => 'Ada Santos',
                'employment_type' => 'full_time',
                'status' => UserStatus::Active->value,
            ]);
            $this->assertDatabaseHas('users', [
                'name' => 'Marian S. Villanueva',
                'college' => CollegeCode::Ccs->value,
                'employment_type' => 'part_time',
                'status' => UserStatus::Active->value,
            ]);
        } finally {
            @unlink($fixturePath);
        }
    }

    public function test_it_creates_active_accounts_from_the_canonical_professor_department_list_and_rewrites_the_directory(): void
    {
        $this->seedReferenceCatalog();
        $existingFaculty = User::create([
            'name' => 'Existing Faculty',
            'email' => 'existing.faculty@grc.test',
            'password' => 'previous-local-password',
            'role' => UserRole::Faculty,
            'college' => CollegeCode::Ccs,
            'status' => UserStatus::Disabled,
        ]);
        $csvPath = $this->writeFixture();
        $directoryPath = $this->writeProfessorDepartmentListFixture();

        try {
            (new WorkbookFacultyProfileSeeder($csvPath, $directoryPath))->run();

            $scopedProfessor = User::query()->where('name', 'Anabel Reyes')->sole();
            $this->assertSame(CollegeCode::Ccs, $scopedProfessor->college);
            $this->assertSame(UserStatus::Active, $scopedProfessor->status);
            $this->assertTrue(Hash::check('password', $scopedProfessor->password));

            $coach = User::query()->where('name', 'Ana Coach')->sole();
            $this->assertNull($coach->college);
            $this->assertSame(UserStatus::Active, $coach->status);

            $unidentified = User::query()->where('name', 'Sofia Maestro')->sole();
            $this->assertStringContainsString(' ', $unidentified->name);
            $this->assertSame(UserStatus::Active, $unidentified->status);

            $existingFaculty->refresh();
            $this->assertSame(UserStatus::Active, $existingFaculty->status);

            $directory = file_get_contents($directoryPath);
            self::assertIsString($directory);
            $this->assertStringContainsString('| Professor Name | Email | Department |', $directory);
            $this->assertStringContainsString($scopedProfessor->email, $directory);
            $this->assertStringContainsString('CCS', $directory);
            $this->assertStringNotContainsString('password', strtolower($directory));

            (new WorkbookFacultyProfileSeeder($csvPath, $directoryPath))->run();
            $rewrittenDirectory = file_get_contents($directoryPath);
            self::assertIsString($rewrittenDirectory);
            $this->assertStringContainsString($scopedProfessor->email, $rewrittenDirectory);
            $this->assertStringContainsString('| Ana Coach |', $rewrittenDirectory);
        } finally {
            @unlink($csvPath);
            @unlink($directoryPath);
        }
    }

    public function test_every_professor_receives_availability_preferences_and_specializations(): void
    {
        $this->seed([
            ProgramSeeder::class,
            GrcSubjectCatalogSeeder::class,
            GrcCurriculumSeeder::class,
        ]);
        $directoryPath = $this->copyRealProfessorDepartmentListFixture();

        try {
            (new WorkbookFacultyProfileSeeder(
                base_path('database/seeders/data/curriculum-2024-2029-schedule-references.csv'),
                $directoryPath,
            ))->run();

            $professors = User::query()
                ->where('role', UserRole::Faculty->value)
                ->orderBy('id')
                ->get();

            $this->assertGreaterThanOrEqual(145, $professors->count());
            $this->assertCount(
                145,
                $professors->filter(
                    static fn (User $professor): bool => str_ends_with($professor->email, '@grc.com'),
                ),
            );
            $this->assertSame(0, FacultyAvailability::query()->where('day_of_week', 7)->count());

            $generalEducationSubjectIds = Subject::query()
                ->select('code')
                ->groupBy('code')
                ->havingRaw('count(distinct college) > 1')
                ->pluck('code');

            foreach ($professors as $professor) {
                $this->assertGreaterThan(
                    0,
                    FacultyAvailability::query()
                        ->where('professor_id', $professor->id)
                        ->where('origin', 'workbook_seeded')
                        ->count(),
                    "{$professor->email} is missing reusable workbook availability.",
                );
                $this->assertGreaterThanOrEqual(
                    4,
                    FacultySpecialization::query()->where('professor_id', $professor->id)->count(),
                    "{$professor->email} is missing seeded specializations.",
                );

                if ($professor->college === null) {
                    $this->assertSame(
                        0,
                        FacultyCurriculumSubjectPreference::query()->where('professor_id', $professor->id)->count(),
                        "{$professor->email} must not receive college curriculum preferences.",
                    );
                    $this->assertSame(
                        0,
                        FacultySpecialization::query()
                            ->where('professor_id', $professor->id)
                            ->whereHas('subject', fn ($query) => $query
                                ->whereNotLike('code', 'NSTP%')
                                ->whereNotLike('code', 'PATHFIT%')
                                ->whereNotLike('code', 'PE%')
                                ->whereNotIn('code', $generalEducationSubjectIds))
                            ->count(),
                        "{$professor->email} must only receive PE, NSTP, or general-education specializations.",
                    );

                    continue;
                }

                $curricula = Curriculum::query()
                    ->whereHas('program', fn ($query) => $query->where('college', $professor->college->value))
                    ->get();
                foreach ($curricula as $curriculum) {
                    foreach (['1st', '2nd'] as $semester) {
                        $availableSubjectCount = CurriculumSubject::query()
                            ->where('curriculum_id', $curriculum->id)
                            ->where(function ($query) use ($semester): void {
                                $query->where('semester', $semester)
                                    ->orWhere('semester', '1st|2nd');
                            })
                            ->count();
                        if ($availableSubjectCount === 0) {
                            continue;
                        }

                        $preferences = FacultyCurriculumSubjectPreference::query()
                            ->where('professor_id', $professor->id)
                            ->where('curriculum_id', $curriculum->id)
                            ->where('semester', $semester)
                            ->where('origin', 'workbook_seeded')
                            ->orderBy('rank')
                            ->get();

                        $this->assertGreaterThanOrEqual(
                            min(5, $availableSubjectCount),
                            $preferences->count(),
                            "{$professor->email} is missing {$curriculum->name} {$semester} preferences.",
                        );
                        // Upper bound is the subject pool itself, not a fixed
                        // 8: a professor's real workbook-derived evidence for
                        // this curriculum/semester is preserved (Finding 1)
                        // and gap-filled on top up to 5-8 *additional*
                        // subjects, so genuine evidence can legitimately push
                        // the total above 8 when the pool is large enough.
                        $this->assertLessThanOrEqual(
                            $availableSubjectCount,
                            $preferences->count(),
                            "{$professor->email} has more {$curriculum->name} {$semester} preferences than subjects exist.",
                        );
                        $this->assertSame(range(1, $preferences->count()), $preferences->pluck('rank')->all());
                    }
                }
            }
        } finally {
            @unlink($directoryPath);
        }
    }

    public function test_it_preserves_every_declared_profile_record_when_completing_a_faculty_profile(): void
    {
        $this->seedReferenceCatalog();
        $professor = User::create([
            'name' => 'Declared Profile Faculty',
            'email' => 'declared.profile.faculty@grc.test',
            'password' => 'previous-local-password',
            'role' => UserRole::Faculty,
            'college' => CollegeCode::Ccs,
            'status' => UserStatus::Active,
        ]);
        $curriculum = Curriculum::query()
            ->whereHas('program', fn ($query) => $query->where('college', CollegeCode::Ccs->value))
            ->sole();
        $subjects = Subject::query()->where('college', CollegeCode::Ccs->value)->orderBy('id')->get();
        FacultyAvailability::create([
            'professor_id' => $professor->id,
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '12:00:00',
            'origin' => 'declared',
        ]);
        FacultyCurriculumSubjectPreference::create([
            'professor_id' => $professor->id,
            'curriculum_id' => $curriculum->id,
            'semester' => '1st',
            'subject_id' => $subjects->first()->id,
            'rank' => 1,
            'origin' => 'declared',
        ]);
        foreach ($subjects->take(7) as $subject) {
            FacultySpecialization::create([
                'professor_id' => $professor->id,
                'subject_id' => $subject->id,
                'proficiency' => 'primary',
                'source' => 'declared',
            ]);
        }
        $fixturePath = $this->writeFixture();

        try {
            (new WorkbookFacultyProfileSeeder($fixturePath))->run();

            $this->assertDatabaseHas('faculty_availabilities', [
                'professor_id' => $professor->id,
                'day_of_week' => 1,
                'starts_at_time' => '08:00:00',
                'ends_at_time' => '12:00:00',
                'origin' => 'declared',
            ]);
            $this->assertDatabaseHas('faculty_curriculum_subject_preferences', [
                'professor_id' => $professor->id,
                'curriculum_id' => $curriculum->id,
                'semester' => '1st',
                'subject_id' => $subjects->first()->id,
                'rank' => 1,
                'origin' => 'declared',
            ]);
            $this->assertSame(7, FacultySpecialization::query()
                ->where('professor_id', $professor->id)
                ->where('source', 'declared')
                ->count());
        } finally {
            @unlink($fixturePath);
        }
    }

    private function seedReferenceCatalog(): void
    {
        $program = Program::create([
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSIT Curriculum 2024-2029',
            'effective_school_year' => '2024-2029',
            'effective_start_year' => 2024,
            'effective_end_year' => 2029,
            'status' => CurriculumStatus::Active,
        ]);
        $sharedProgram = Program::create([
            'code' => 'SHARED',
            'name' => 'Shared catalog program',
            'college' => null,
            'status' => ProgramStatus::Active,
        ]);
        Curriculum::create([
            'program_id' => $sharedProgram->id,
            'name' => 'Shared curriculum',
            'effective_school_year' => '2024-2029',
            'effective_start_year' => 2024,
            'effective_end_year' => 2029,
            'status' => CurriculumStatus::Active,
        ]);
        foreach ([
            ['IT101', '1st'], ['CS101', '1st'], ['CS102', '1st'], ['CS103', '1st'],
            ['IT201', '2nd'], ['CS201', '2nd'], ['CS202', '2nd'], ['CS203', '2nd'],
        ] as [$code, $semester]) {
            $subject = Subject::create([
                'code' => $code,
                'college' => CollegeCode::Ccs,
                'title' => $code.' title',
                'units' => 3,
                'status' => SubjectStatus::Active,
            ]);
            CurriculumSubject::create([
                'curriculum_id' => $curriculum->id,
                'subject_id' => $subject->id,
                'year_level' => 1,
                'semester' => $semester,
                'is_required' => true,
            ]);
        }
        Subject::create([
            'code' => 'COMMON101',
            'college' => null,
            'title' => 'Shared catalog subject',
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);
        foreach (['1st', '2nd'] as $semester) {
            AcademicTerm::create([
                'school_year' => '2027-2028',
                'semester' => $semester,
                'status' => AcademicTermStatus::Draft,
            ]);
        }
    }

    /** @param list<array{string, string, string, string, string, string, string, string}>|null $rows */
    private function writeFixture(?array $rows = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grc_workbook_faculty_').'.csv';
        if ($rows === null) {
            file_put_contents($path, self::FIXTURE_CSV);

            return $path;
        }
        $lines = ['college,program_code,year_level,semester,subject_code,day,start_time,end_time,room,modality,professor_name,sched_id,notes'];
        foreach ($rows as $index => [$college, $program, $semester, $code, $day, $start, $end, $professor]) {
            $lines[] = implode(',', [$college, $program, 1, $semester, $code, $day, $start, $end, '3A', 'F2F', $professor, 100 + $index, '']);
        }
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);

        return $path;
    }

    /**
     * The real, git-tracked professor directory is read-only source data for
     * the seeder to synchronize against, but `WorkbookFacultyProfileSeeder`
     * writes its resolved directory back to whatever path it is given.
     * Tests that need the real 145-professor directory content must operate
     * on a disposable copy, never the tracked file itself.
     */
    private function copyRealProfessorDepartmentListFixture(): string
    {
        $source = base_path('../Subject And Prerequisuite/Professor_Department_List.md');
        $path = tempnam(sys_get_temp_dir(), 'grc_professor_directory_real_').'.md';
        copy($source, $path);

        return $path;
    }

    private function writeProfessorDepartmentListFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grc_professor_directory_').'.md';
        file_put_contents($path, <<<'MARKDOWN'
# Professor and Department List

| No. | Professor Name | Department |
|---:|---|---|
| 1 | Dr. Anabel Reyes | CCS |
| 2 | Ana Coach | Coaches |
| 3 | Maestro | Unidentified |
MARKDOWN);

        return $path;
    }
}
