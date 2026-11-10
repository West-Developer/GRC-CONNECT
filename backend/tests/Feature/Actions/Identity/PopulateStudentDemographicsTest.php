<?php

namespace Tests\Feature\Actions\Identity;

use App\Actions\Identity\PopulateStudentDemographics;
use App\Domain\Identity\Gender;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsChedReportFixtures;
use Tests\TestCase;

final class PopulateStudentDemographicsTest extends TestCase
{
    use BuildsChedReportFixtures;
    use RefreshDatabase;

    private const FIELDS = [
        'gender', 'birth_date', 'citizenship', 'contact_number', 'street_barangay', 'town_city', 'province',
    ];

    /**
     * @param  array<string, mixed>  $profile
     */
    private function blankStudent(string $firstName, array $profile = []): StudentProfile
    {
        return $this->chedStudent(
            ['first_name' => $firstName, 'name' => $firstName.' D. Santos'],
            array_merge(['gender' => null, 'citizenship' => null], $profile),
        );
    }

    public function test_it_fills_every_missing_detail_and_remembers_which_ones_it_filled(): void
    {
        $student = $this->blankStudent('Jose');

        $report = $this->app->make(PopulateStudentDemographics::class)->populate();

        $student->refresh();
        $this->assertSame(Gender::Male, $student->gender);
        $this->assertSame('Filipino', $student->citizenship);
        $this->assertNotNull($student->birth_date);
        $this->assertMatchesRegularExpression('/^09\d{9}$/', (string) $student->contact_number);
        $this->assertNotSame('', (string) $student->street_barangay);
        $this->assertNotSame('', (string) $student->town_city);
        $this->assertNotSame('', (string) $student->province);
        $this->assertEqualsCanonicalizing(self::FIELDS, $student->demographics_demo_fields);
        $this->assertSame(1, $report['updated']);
        $this->assertSame(1, $report['gender']['name']);
    }

    public function test_the_birth_date_is_an_adult_age_for_the_entry_year(): void
    {
        $student = $this->blankStudent('Maria', ['entry_year' => 2025, 'year_level' => 1]);

        $this->app->make(PopulateStudentDemographics::class)->populate();

        $age = 2025 - $student->refresh()->birth_date->year;
        $this->assertGreaterThanOrEqual(17, $age);
        $this->assertLessThanOrEqual(21, $age);
    }

    public function test_it_never_overwrites_a_real_value(): void
    {
        $student = $this->blankStudent('Jose', [
            'gender' => Gender::Female,
            'citizenship' => 'American',
            'contact_number' => '09170000000',
        ]);

        $this->app->make(PopulateStudentDemographics::class)->populate();

        $student->refresh();
        $this->assertSame(Gender::Female, $student->gender);
        $this->assertSame('American', $student->citizenship);
        $this->assertSame('09170000000', $student->contact_number);
        $this->assertEqualsCanonicalizing(
            ['birth_date', 'street_barangay', 'town_city', 'province'],
            $student->demographics_demo_fields,
        );
    }

    public function test_a_student_with_a_written_address_gets_no_invented_street_town_or_province(): void
    {
        $student = $this->blankStudent('Maria', ['address' => '12 Rizal Ave, Caloocan City']);

        $this->app->make(PopulateStudentDemographics::class)->populate();

        $student->refresh();
        $this->assertNull($student->street_barangay);
        $this->assertNull($student->town_city);
        $this->assertNull($student->province);
        $this->assertSame('12 Rizal Ave, Caloocan City', $student->address);
        $this->assertNotContains('town_city', $student->demographics_demo_fields);
    }

    public function test_demo_accounts_are_left_alone(): void
    {
        $student = $this->blankStudent('Jose', ['is_demo_account' => true]);

        $report = $this->app->make(PopulateStudentDemographics::class)->populate();

        $student->refresh();
        $this->assertNull($student->gender);
        $this->assertNull($student->demographics_demo_fields);
        $this->assertSame(0, $report['updated']);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $student = $this->blankStudent('Jose');
        $action = $this->app->make(PopulateStudentDemographics::class);
        $action->populate();
        $first = $student->refresh()->only([...self::FIELDS, 'demographics_demo_fields']);

        $report = $action->populate();

        $this->assertSame(0, $report['updated']);
        $this->assertEquals($first, $student->refresh()->only([...self::FIELDS, 'demographics_demo_fields']));
    }

    public function test_a_dry_run_reports_but_writes_nothing(): void
    {
        $student = $this->blankStudent('Jose');

        $report = $this->app->make(PopulateStudentDemographics::class)->populate(dryRun: true);

        $this->assertSame(1, $report['updated']);
        $this->assertSame(1, $report['fields']['gender']);
        $this->assertNull($student->refresh()->gender);
        $this->assertNull($student->demographics_demo_fields);
    }

    public function test_an_unknown_name_still_gets_a_gender_and_is_counted_apart(): void
    {
        $student = $this->blankStudent('Xqzvt');

        $report = $this->app->make(PopulateStudentDemographics::class)->populate();

        $this->assertNotNull($student->refresh()->gender);
        $this->assertSame(1, $report['gender']['fallback']);
        $this->assertSame(0, $report['gender']['name']);
    }

    public function test_a_name_known_only_by_its_ending_is_counted_as_an_ending_guess(): void
    {
        $student = $this->blankStudent('Marianela');

        $report = $this->app->make(PopulateStudentDemographics::class)->populate();

        $this->assertSame(Gender::Female, $student->refresh()->gender);
        $this->assertSame(1, $report['gender']['ending']);
    }

    public function test_a_legacy_user_with_only_a_full_name_is_read_from_that_name(): void
    {
        $student = $this->chedStudent(
            ['first_name' => null, 'last_name' => null, 'middle_initial' => null, 'name' => 'DELA CRUZ, MARIA A.'],
            ['gender' => null],
        );

        $this->app->make(PopulateStudentDemographics::class)->populate();

        $this->assertSame(Gender::Female, $student->refresh()->gender);
    }

    public function test_it_does_not_touch_updated_at(): void
    {
        $student = $this->blankStudent('Jose');
        StudentProfile::query()->whereKey($student->id)->update(['updated_at' => '2026-01-02 03:04:05']);

        $this->app->make(PopulateStudentDemographics::class)->populate();

        $this->assertSame('2026-01-02 03:04:05', $student->refresh()->updated_at?->toDateTimeString());
    }

    public function test_wipe_takes_back_exactly_the_generated_fields(): void
    {
        $student = $this->blankStudent('Jose', ['gender' => Gender::Female, 'contact_number' => '09170000000']);
        $action = $this->app->make(PopulateStudentDemographics::class);
        $action->populate();

        $report = $action->wipe();

        $student->refresh();
        $this->assertSame(1, $report['wiped']);
        $this->assertSame(Gender::Female, $student->gender, 'a real value stays');
        $this->assertSame('09170000000', $student->contact_number, 'a real value stays');
        $this->assertNull($student->birth_date);
        $this->assertNull($student->citizenship);
        $this->assertNull($student->street_barangay);
        $this->assertNull($student->town_city);
        $this->assertNull($student->province);
        $this->assertNull($student->demographics_demo_fields);
    }

    public function test_a_wipe_dry_run_writes_nothing(): void
    {
        $student = $this->blankStudent('Jose');
        $action = $this->app->make(PopulateStudentDemographics::class);
        $action->populate();

        $report = $action->wipe(dryRun: true);

        $this->assertSame(1, $report['wiped']);
        $this->assertNotNull($student->refresh()->birth_date);
    }
}
