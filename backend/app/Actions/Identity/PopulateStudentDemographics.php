<?php

namespace App\Actions\Identity;

use App\Domain\Identity\DemographicsPopulationReport;
use App\Domain\Identity\FirstNameGenderGuesser;
use App\Domain\Identity\PersonName;
use App\Domain\Identity\StudentDemographicsGenerator;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fills the student details the CHED forms need and the system never stored (ADR 0049): gender,
 * birth date, citizenship, contact number and the street / town / province split of the address.
 *
 * It only ever writes a field that is empty, skips demo accounts, and records the field names it
 * filled in `student_profiles.demographics_demo_fields`, so the values stay recognisable as
 * generated and `wipe()` can take exactly those back. It does not touch `updated_at`: filling a
 * form field is not a student edit. The street / town / province split is generated only when the
 * student has no written address at all; an existing address is never second-guessed.
 */
final class PopulateStudentDemographics
{
    private const CHUNK = 500;

    public function __construct(
        private readonly FirstNameGenderGuesser $guesser,
        private readonly StudentDemographicsGenerator $generator,
    ) {}

    /**
     * @return array{considered: int, updated: int, fields: array<string, int>, gender: array{name: int, ending: int, fallback: int}}
     */
    public function populate(bool $dryRun = false): array
    {
        $report = new DemographicsPopulationReport;

        $this->missingSomething(StudentProfile::query())
            ->with('user')
            ->chunkById(self::CHUNK, function ($profiles) use ($report, $dryRun): void {
                DB::transaction(function () use ($profiles, $report, $dryRun): void {
                    /** @var StudentProfile $profile */
                    foreach ($profiles as $profile) {
                        $report->considered++;
                        [$fill, $genderSource] = $this->plan($profile);

                        if ($fill === []) {
                            continue;
                        }

                        $report->filled(array_keys($fill), $genderSource);

                        if (! $dryRun) {
                            $this->write($profile, $fill);
                        }
                    }
                });
            });

        return $report->toArray();
    }

    /**
     * Takes back exactly the fields `populate()` filled, and nothing a person entered.
     *
     * @return array{wiped: int}
     */
    public function wipe(bool $dryRun = false): array
    {
        $wiped = 0;

        StudentProfile::query()
            ->whereNotNull('demographics_demo_fields')
            ->chunkById(self::CHUNK, function ($profiles) use (&$wiped, $dryRun): void {
                DB::transaction(function () use ($profiles, &$wiped, $dryRun): void {
                    /** @var StudentProfile $profile */
                    foreach ($profiles as $profile) {
                        $fields = $profile->demographics_demo_fields ?? [];
                        $wiped++;

                        if ($dryRun) {
                            continue;
                        }

                        $profile->forceFill(array_merge(
                            array_fill_keys($fields, null),
                            ['demographics_demo_fields' => null],
                        ));
                        $profile->timestamps = false;
                        $profile->save();
                    }
                });
            });

        return ['wiped' => $wiped];
    }

    /**
     * @param  Builder<StudentProfile>  $query
     * @return Builder<StudentProfile>
     */
    private function missingSomething(Builder $query): Builder
    {
        return $query
            ->where('is_demo_account', false)
            ->where(function (Builder $missing): void {
                $missing->whereNull('gender')
                    ->orWhereNull('birth_date')
                    ->orWhereNull('citizenship')
                    ->orWhereNull('contact_number')
                    ->orWhere(function (Builder $address): void {
                        $address->where(fn (Builder $none) => $none->whereNull('address')->orWhere('address', ''))
                            ->where(function (Builder $split): void {
                                $split->whereNull('street_barangay')
                                    ->orWhereNull('town_city')
                                    ->orWhereNull('province');
                            });
                    });
            });
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string} the fields to fill, and how the gender was decided
     */
    private function plan(StudentProfile $profile): array
    {
        $fill = [];
        $genderSource = null;
        $seed = $profile->id;

        if ($profile->gender === null) {
            $name = $this->firstName($profile->user);

            if (($gender = $this->guesser->guess($name)) !== null) {
                $genderSource = DemographicsPopulationReport::BY_NAME;
            } elseif (($gender = $this->guesser->guessFromEnding($name)) !== null) {
                $genderSource = DemographicsPopulationReport::BY_ENDING;
            } else {
                $gender = $this->generator->fallbackGender($seed);
                $genderSource = DemographicsPopulationReport::BY_FALLBACK;
            }

            $fill['gender'] = $gender;
        }

        if ($profile->birth_date === null) {
            $fill['birth_date'] = $this->generator->birthDate($seed, $profile->entry_year, $profile->year_level);
        }

        if (blank($profile->citizenship)) {
            $fill['citizenship'] = $this->generator->citizenship();
        }

        if (blank($profile->contact_number)) {
            $fill['contact_number'] = $this->generator->contactNumber($seed);
        }

        if (blank($profile->address)) {
            foreach ($this->generator->address($seed) as $field => $value) {
                if (blank($profile->{$field})) {
                    $fill[$field] = $value;
                }
            }
        }

        return [$fill, $genderSource];
    }

    /**
     * @param  array<string, mixed>  $fill
     */
    private function write(StudentProfile $profile, array $fill): void
    {
        $profile->forceFill($fill + [
            'demographics_demo_fields' => array_values(array_unique(array_merge(
                $profile->demographics_demo_fields ?? [],
                array_keys($fill),
            ))),
        ]);
        $profile->timestamps = false;
        $profile->save();
    }

    private function firstName(User $user): ?string
    {
        if (filled($user->first_name)) {
            return $user->first_name;
        }

        $name = trim($user->name);

        return $name === '' ? null : PersonName::split($name)['first_name'];
    }
}
