<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumVersion;
use App\Domain\Identity\PersonName;
use App\Domain\Identity\StudentAddress;
use App\Models\Curriculum;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateStudentProfile
{
    private const PERSONAL_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'suffix', 'email',
        'gender', 'birth_date', 'citizenship', 'contact_number', 'street_barangay', 'town_city', 'province',
    ];

    /** The details the CHED forms ask for (ADR 0049): one a person enters is no longer a generated value. */
    private const DEMOGRAPHIC_FIELDS = [
        'gender', 'birth_date', 'citizenship', 'contact_number', 'street_barangay', 'town_city', 'province',
    ];

    private const NAME_PART_FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix'];

    /**
     * `entry_year` is deliberately absent (derived from the ongoing term). `student_type` is chosen by
     * Admission (stakeholder Doc 20) and is not derived from `year_level`, so changing the year level
     * never overwrites it. `enrollment_category` is not here: the system decides Regular or Irregular.
     */
    private const ACADEMIC_SETUP_FIELDS = [
        'student_number',
        'program_id',
        'year_level',
        'student_type',
        'financial_status',
        'admission_status',
    ];

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /** @param array<string, mixed> $data */
    public function handle(
        StudentProfile $profile,
        array $data,
        User $actor,
        AuditRequestContext $context,
    ): StudentProfile {
        return DB::transaction(function () use ($profile, $data, $actor, $context): StudentProfile {
            $locked = StudentProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $requestedFields = array_values(array_intersect(
                array_keys($data),
                [...self::PERSONAL_FIELDS, ...self::ACADEMIC_SETUP_FIELDS],
            ));

            if ($requestedFields === []) {
                throw ValidationException::withMessages([
                    'profile' => 'At least one student information field must be changed.',
                ]);
            }

            $academicFields = array_values(array_intersect($requestedFields, self::ACADEMIC_SETUP_FIELDS));
            if ($academicFields !== [] && $locked->enrollments()->exists()) {
                throw ValidationException::withMessages([
                    'profile' => 'Academic setup fields cannot be changed after the student has an enrollment record.',
                ]);
            }

            $userData = Arr::only($data, [...self::NAME_PART_FIELDS, 'email']);
            foreach (['first_name', 'last_name'] as $namePart) {
                if (array_key_exists($namePart, $userData)) {
                    $userData[$namePart] = PersonName::normalizeNamePart($userData[$namePart]);
                }
            }
            // The full middle name decides the initial; a value sent back as it already is changes neither.
            $middle = ['middle_name' => $user->middle_name, 'middle_initial' => $user->middle_initial];
            if (array_key_exists('middle_name', $userData)) {
                $middle = PersonName::middle($userData['middle_name'], $user->middle_name, $user->middle_initial);
                $userData['middle_name'] = $middle['middle_name'];
                $userData['middle_initial'] = $middle['middle_initial'];
            }
            if (array_key_exists('suffix', $userData)) {
                $userData['suffix'] = PersonName::normalizeSuffix($userData['suffix']);
            }
            if (array_intersect(array_keys($userData), self::NAME_PART_FIELDS) !== []) {
                $userData['name'] = PersonName::compose(
                    $userData['first_name'] ?? $user->first_name ?? '',
                    $middle['middle_initial'],
                    $userData['last_name'] ?? $user->last_name ?? '',
                    array_key_exists('suffix', $userData) ? $userData['suffix'] : $user->suffix,
                );
            }
            if ($userData !== []) {
                $user->fill($userData)->save();
            }

            $profileData = Arr::only($data, [
                ...self::DEMOGRAPHIC_FIELDS,
                'student_number',
                'program_id',
                'year_level',
                'student_type',
                'financial_status',
                'admission_status',
            ]);

            // The three address parts are the only source of the single address text; a request may not set it.
            // It is rewritten once all three are known, so a partly typed address on an older account never
            // replaces the free text that account has with a fragment of it.
            if (array_intersect(['street_barangay', 'town_city', 'province'], array_keys($profileData)) !== []) {
                $street = $profileData['street_barangay'] ?? $locked->street_barangay;
                $town = $profileData['town_city'] ?? $locked->town_city;
                $province = $profileData['province'] ?? $locked->province;

                if (trim((string) $street) !== '' && trim((string) $town) !== '' && trim((string) $province) !== '') {
                    $profileData['address'] = StudentAddress::compose($street, $town, $province);
                }
            }

            // A value Admission types replaces a generated one, which is then no longer marked generated. The
            // correction form sends every detail it shows, so a value sent unchanged is not a typed one and stays
            // generated.
            $entered = array_values(array_filter(
                self::DEMOGRAPHIC_FIELDS,
                static fn (string $field): bool => array_key_exists($field, $profileData)
                    && (string) $profileData[$field] !== (string) self::currentDetail($locked, $field),
            ));
            if ($entered !== [] && $locked->demographics_demo_fields !== null) {
                $stillGenerated = array_values(array_diff($locked->demographics_demo_fields, $entered));
                $profileData['demographics_demo_fields'] = $stillGenerated === [] ? null : $stillGenerated;
            }

            if (array_key_exists('program_id', $profileData)) {
                $programId = (int) $profileData['program_id'];
                $entryYear = (int) $locked->entry_year;
                $curriculum = CurriculumVersion::resolveForEntryYear(
                    Curriculum::query()
                        ->where('program_id', $programId)
                        ->orderByDesc('effective_start_year')
                        ->get(),
                    $entryYear,
                );

                if ($curriculum === null) {
                    throw ValidationException::withMessages([
                        'entry_year' => 'No curriculum version is configured for this program and entry year.',
                    ]);
                }

                $profileData['curriculum_id'] = $curriculum->id;
            }

            $locked->fill($profileData)->save();

            $this->auditRecorder->record(
                $actor,
                AuditAction::STUDENT_PROFILE_UPDATED,
                AuditableType::STUDENT_PROFILE,
                $locked->id,
                ['field_count' => count($requestedFields)],
                ['changed_fields' => $requestedFields, 'reason_provided' => true],
                null,
                $context,
            );

            return $locked->refresh()->load(['user', 'program', 'curriculum']);
        });
    }

    /** What is stored for one of the details, in the form a request sends it. */
    private static function currentDetail(StudentProfile $profile, string $field): ?string
    {
        return match ($field) {
            'gender' => $profile->gender?->value,
            'birth_date' => $profile->birth_date?->format('Y-m-d'),
            default => $profile->{$field},
        };
    }
}
