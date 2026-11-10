<?php

namespace Tests\Feature\Console;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `faculty:merge-duplicates` folds legacy `@grc.test` faculty accounts into the
 * `@grc.com` account of the same professor so that the sections a student sees
 * under a professor's name are the sections that professor sees when logged in.
 */
final class MergeDuplicateFacultyAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $manifestDir;

    private AcademicTerm $term;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'faculty-merge-'.uniqid();
        $this->term = AcademicTerm::create([
            'school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->manifestDir);

        parent::tearDown();
    }

    private function faculty(string $name, string $email, ?CollegeCode $college): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'secret-password-1',
            'role' => UserRole::Faculty,
            'status' => UserStatus::Active,
            'college' => $college,
        ]);
    }

    private function registrarHead(): User
    {
        return User::create([
            'name' => 'Registrar Head', 'email' => 'rh.merge@grc.test', 'password' => 'secret-password-1',
            'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active,
        ]);
    }

    private function section(User $professor): Section
    {
        $subject = Subject::create([
            'code' => 'MRG'.(++$this->seq), 'title' => 'Subject '.$this->seq, 'units' => 3, 'status' => SubjectStatus::Active,
        ]);

        return Section::create([
            'academic_term_id' => $this->term->id, 'subject_id' => $subject->id, 'section_code' => 'S'.$this->seq,
            'professor_id' => $professor->id, 'capacity' => 40, 'status' => SectionStatus::Published,
        ]);
    }

    private function specialization(User $professor, Subject $subject): int
    {
        return DB::table('faculty_specializations')->insertGetId([
            'professor_id' => $professor->id, 'subject_id' => $subject->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function availability(User $professor, int $day, string $start, string $end): int
    {
        return DB::table('faculty_availabilities')->insertGetId([
            'professor_id' => $professor->id, 'day_of_week' => $day,
            'starts_at_time' => $start, 'ends_at_time' => $end,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $files = File::files($this->manifestDir);
        self::assertCount(1, $files);

        return json_decode(File::get($files[0]->getPathname()), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- Dry run ---------------------------------------------------------

    public function test_a_dry_run_reports_the_merge_and_writes_nothing(): void
    {
        $legacy = $this->faculty('Ana Dela Cruz', 'ana.legacy@grc.test', CollegeCode::Ccs);
        $twin = $this->faculty('Ana Dela Cruz', 'ana.delacruz.ccs@grc.com', CollegeCode::Ccs);
        $section = $this->section($legacy);

        $this->artisan('faculty:merge-duplicates')
            ->expectsOutputToContain('1 legacy account(s) have exactly one credentialed twin')
            ->expectsOutputToContain('Dry run: nothing was written')
            ->assertSuccessful();

        self::assertSame($legacy->id, $section->refresh()->professor_id);
        self::assertSame(UserStatus::Active, $legacy->refresh()->status);
        self::assertSame(0, Section::query()->where('professor_id', $twin->id)->count());
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::FACULTY_ACCOUNT_MERGED)->count());
    }

    // --- Matching rules ---------------------------------------------------

    public function test_only_an_exact_name_and_college_match_with_a_single_twin_is_merged(): void
    {
        $this->registrarHead();

        // Matches, despite case and stray spaces in the legacy name.
        $legacyMatch = $this->faculty('  eva   SANTOS ', 'eva.legacy@grc.test', CollegeCode::Coe);
        $twinMatch = $this->faculty('Eva Santos', 'eva.santos.coe@grc.com', CollegeCode::Coe);

        // Two credentialed accounts share the name and college: ambiguous.
        $ambiguous = $this->faculty('Ben Reyes', 'ben.legacy@grc.test', CollegeCode::Ccs);
        $this->faculty('Ben Reyes', 'ben.reyes.a.ccs@grc.com', CollegeCode::Ccs);
        $this->faculty('Ben Reyes', 'ben.reyes.b.ccs@grc.com', CollegeCode::Ccs);

        // No credentialed account at all.
        $lonely = $this->faculty('Cara Lim', 'cara.legacy@grc.test', CollegeCode::Ccs);

        // Same name but a different college is a different person.
        $otherCollege = $this->faculty('Dan Uy', 'dan.legacy@grc.test', CollegeCode::Ccs);
        $this->faculty('Dan Uy', 'dan.uy.coe@grc.com', CollegeCode::Coe);

        // No college on the legacy account: nothing to match on.
        $noCollege = $this->faculty('Fay Go', 'fay.legacy@grc.test', null);
        $this->faculty('Fay Go', 'fay.go.ccs@grc.com', CollegeCode::Ccs);

        $sections = [];
        foreach ([$legacyMatch, $ambiguous, $lonely, $otherCollege, $noCollege] as $user) {
            $sections[$user->id] = $this->section($user);
        }

        $this->artisan('faculty:merge-duplicates', ['--apply' => true, '--manifest-dir' => $this->manifestDir])
            ->expectsOutputToContain('Merged 1 legacy account(s)')
            ->assertSuccessful();

        self::assertSame($twinMatch->id, $sections[$legacyMatch->id]->refresh()->professor_id);
        self::assertSame(UserStatus::Disabled, $legacyMatch->refresh()->status);

        foreach ([$ambiguous, $lonely, $otherCollege, $noCollege] as $untouched) {
            self::assertSame($untouched->id, $sections[$untouched->id]->refresh()->professor_id, "{$untouched->name} must not move.");
            self::assertSame(UserStatus::Active, $untouched->refresh()->status);
        }
    }

    public function test_a_professor_invited_with_a_real_email_is_a_valid_twin(): void
    {
        $this->registrarHead();
        $legacy = $this->faculty('Gina Ramos', 'gina.legacy@grc.test', CollegeCode::Coe);
        $invited = $this->faculty('Gina Ramos', 'gina.ramos@example.edu', CollegeCode::Coe);
        $section = $this->section($legacy);

        $this->artisan('faculty:merge-duplicates', ['--apply' => true, '--manifest-dir' => $this->manifestDir])->assertSuccessful();

        self::assertSame($invited->id, $section->refresh()->professor_id);
    }

    // --- Apply -----------------------------------------------------------

    public function test_apply_makes_the_sections_visible_to_the_professor_who_logs_in(): void
    {
        $this->registrarHead();
        $legacy = $this->faculty('Ana Dela Cruz', 'ana.legacy@grc.test', CollegeCode::Ccs);
        $twin = $this->faculty('Ana Dela Cruz', 'ana.delacruz.ccs@grc.com', CollegeCode::Ccs);
        $this->section($legacy);
        $this->section($legacy);
        $this->section($twin);

        self::assertSame(1, Section::query()->visibleTo($twin)->count(), 'Before the merge the professor only sees their own section.');

        $this->artisan('faculty:merge-duplicates', ['--apply' => true, '--manifest-dir' => $this->manifestDir])->assertSuccessful();

        self::assertSame(3, Section::query()->visibleTo($twin)->count());
        self::assertSame(0, Section::query()->visibleTo($legacy)->count());
        self::assertSame('Merged into faculty account #'.$twin->id.' (faculty:merge-duplicates)', $legacy->refresh()->deactivation_reason);
    }

    public function test_apply_moves_professor_data_but_keeps_the_twins_own_row_on_a_unique_key_clash(): void
    {
        $this->registrarHead();
        $legacy = $this->faculty('Ana Dela Cruz', 'ana.legacy@grc.test', CollegeCode::Ccs);
        $twin = $this->faculty('Ana Dela Cruz', 'ana.delacruz.ccs@grc.com', CollegeCode::Ccs);

        $shared = Subject::create(['code' => 'SHR1', 'title' => 'Shared', 'units' => 3, 'status' => SubjectStatus::Active]);
        $only = Subject::create(['code' => 'ONL1', 'title' => 'Legacy only', 'units' => 3, 'status' => SubjectStatus::Active]);
        $legacyShared = $this->specialization($legacy, $shared);
        $legacyOnly = $this->specialization($legacy, $only);
        $twinShared = $this->specialization($twin, $shared);

        $legacyClash = $this->availability($legacy, 1, '08:00:00', '10:00:00');
        $legacyFree = $this->availability($legacy, 2, '08:00:00', '10:00:00');
        $twinClash = $this->availability($twin, 1, '08:00:00', '12:00:00');

        $this->artisan('faculty:merge-duplicates', ['--apply' => true, '--manifest-dir' => $this->manifestDir])->assertSuccessful();

        $owner = fn (string $table, int $id): int => (int) DB::table($table)->where('id', $id)->value('professor_id');

        self::assertSame($twin->id, $owner('faculty_specializations', $legacyOnly));
        self::assertSame($legacy->id, $owner('faculty_specializations', $legacyShared), 'The clashing legacy row stays put.');
        self::assertSame($twin->id, $owner('faculty_specializations', $twinShared), 'The twin keeps its own row.');

        self::assertSame($twin->id, $owner('faculty_availabilities', $legacyFree));
        self::assertSame($legacy->id, $owner('faculty_availabilities', $legacyClash));
        self::assertSame($twin->id, $owner('faculty_availabilities', $twinClash));

        $moved = $this->manifest()['pairs'][0];
        self::assertSame([$legacyOnly], $moved['moved']['faculty_specializations']);
        self::assertSame([$legacyShared], $moved['kept']['faculty_specializations']);
    }

    public function test_apply_is_audited_and_writes_a_manifest_and_is_idempotent(): void
    {
        $actor = $this->registrarHead();
        $legacy = $this->faculty('Ana Dela Cruz', 'ana.legacy@grc.test', CollegeCode::Ccs);
        $twin = $this->faculty('Ana Dela Cruz', 'ana.delacruz.ccs@grc.com', CollegeCode::Ccs);
        $this->section($legacy);

        $this->artisan('faculty:merge-duplicates', ['--apply' => true, '--manifest-dir' => $this->manifestDir])->assertSuccessful();

        $audit = AuditLog::query()->where('action', AuditAction::FACULTY_ACCOUNT_MERGED)->sole();
        self::assertSame($actor->id, $audit->actor_user_id);
        self::assertSame($legacy->id, $audit->auditable_id);
        self::assertSame($twin->id, $audit->after_values['merged_into_user_id']);
        self::assertSame(1, $audit->after_values['moved_rows']['sections']);

        // A second run finds nothing left to merge: the legacy account is disabled.
        $this->artisan('faculty:merge-duplicates', ['--apply' => true, '--manifest-dir' => $this->manifestDir])
            ->expectsOutputToContain('Merged 0 legacy account(s)')
            ->assertSuccessful();
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::FACULTY_ACCOUNT_MERGED)->count());
    }

    public function test_apply_refuses_to_run_without_a_registrar_head_to_audit_as(): void
    {
        $legacy = $this->faculty('Ana Dela Cruz', 'ana.legacy@grc.test', CollegeCode::Ccs);
        $this->faculty('Ana Dela Cruz', 'ana.delacruz.ccs@grc.com', CollegeCode::Ccs);
        $section = $this->section($legacy);

        $this->artisan('faculty:merge-duplicates', ['--apply' => true, '--manifest-dir' => $this->manifestDir])
            ->expectsOutputToContain('No Registrar Head account found')
            ->assertFailed();

        self::assertSame($legacy->id, $section->refresh()->professor_id);
        self::assertSame(UserStatus::Active, $legacy->refresh()->status);
    }

    // --- Rollback --------------------------------------------------------

    public function test_a_rollback_restores_the_moved_rows_and_the_legacy_account(): void
    {
        $this->registrarHead();
        $legacy = $this->faculty('Ana Dela Cruz', 'ana.legacy@grc.test', CollegeCode::Ccs);
        $twin = $this->faculty('Ana Dela Cruz', 'ana.delacruz.ccs@grc.com', CollegeCode::Ccs);
        $legacySection = $this->section($legacy);
        $twinSection = $this->section($twin);
        $subject = Subject::create(['code' => 'ONL1', 'title' => 'Legacy only', 'units' => 3, 'status' => SubjectStatus::Active]);
        $specialization = $this->specialization($legacy, $subject);

        $this->artisan('faculty:merge-duplicates', ['--apply' => true, '--manifest-dir' => $this->manifestDir])->assertSuccessful();
        self::assertSame($twin->id, $legacySection->refresh()->professor_id);

        $path = File::files($this->manifestDir)[0]->getPathname();
        $this->artisan('faculty:merge-duplicates', ['--rollback' => $path])
            ->expectsOutputToContain('Restored 1 legacy account(s) and moved 2 row(s) back')
            ->assertSuccessful();

        self::assertSame($legacy->id, $legacySection->refresh()->professor_id);
        self::assertSame($twin->id, $twinSection->refresh()->professor_id, "The twin's own section is never touched.");
        self::assertSame($legacy->id, (int) DB::table('faculty_specializations')->where('id', $specialization)->value('professor_id'));
        self::assertSame(UserStatus::Active, $legacy->refresh()->status);
        self::assertNull($legacy->deactivation_reason);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::FACULTY_ACCOUNT_MERGE_ROLLED_BACK)->count());
    }

    public function test_a_rollback_does_not_overwrite_a_row_that_was_reassigned_since(): void
    {
        $this->registrarHead();
        $legacy = $this->faculty('Ana Dela Cruz', 'ana.legacy@grc.test', CollegeCode::Ccs);
        $twin = $this->faculty('Ana Dela Cruz', 'ana.delacruz.ccs@grc.com', CollegeCode::Ccs);
        $other = $this->faculty('Someone Else', 'someone.else.ccs@grc.com', CollegeCode::Ccs);
        $section = $this->section($legacy);

        $this->artisan('faculty:merge-duplicates', ['--apply' => true, '--manifest-dir' => $this->manifestDir])->assertSuccessful();
        $section->refresh()->update(['professor_id' => $other->id]);

        $path = File::files($this->manifestDir)[0]->getPathname();
        $this->artisan('faculty:merge-duplicates', ['--rollback' => $path])->assertSuccessful();

        self::assertSame($other->id, $section->refresh()->professor_id, 'A later reassignment is preserved.');
        self::assertNotSame($twin->id, $section->professor_id);
    }

    public function test_a_rollback_rejects_a_file_that_is_not_a_manifest(): void
    {
        $this->registrarHead();
        File::ensureDirectoryExists($this->manifestDir);
        $path = $this->manifestDir.DIRECTORY_SEPARATOR.'bad.json';
        File::put($path, '{"hello": "world"}');

        $this->artisan('faculty:merge-duplicates', ['--rollback' => $path])
            ->expectsOutputToContain('not a faculty merge manifest')
            ->assertFailed();
    }
}
