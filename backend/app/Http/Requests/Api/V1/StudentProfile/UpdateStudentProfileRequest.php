<?php

namespace App\Http\Requests\Api\V1\StudentProfile;

use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\FinancialStatus;
use App\Domain\Identity\Gender;
use App\Domain\Identity\StudentType;
use App\Models\StudentProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $profile = $this->route('studentProfile');
        $profileId = $profile instanceof StudentProfile ? $profile->id : null;
        $userId = $profile instanceof StudentProfile ? $profile->user_id : null;

        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'suffix' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            // The details the CHED forms ask for (ADR 0049). Sending any of the three address parts rewrites
            // the single `address` text from them, so a client may not send that text itself.
            'address' => ['prohibited'],
            'gender' => ['sometimes', Rule::enum(Gender::class)],
            'birth_date' => ['sometimes', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'citizenship' => ['sometimes', 'string', 'max:100'],
            'contact_number' => ['sometimes', 'string', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'street_barangay' => ['sometimes', 'string', 'max:255'],
            'town_city' => ['sometimes', 'string', 'max:255'],
            'province' => ['sometimes', 'string', 'max:255'],
            'student_number' => ['sometimes', 'string', 'max:255', 'regex:/^\d{4}-(0[1-9]|1[0-2])-\d{5}$/', Rule::unique('student_profiles', 'student_number')->ignore($profileId)],
            'program_id' => ['sometimes', 'integer', Rule::exists('programs', 'id')],
            // Derived from the current ongoing academic term — see
            // StoreStudentProfileRequest and App\Actions\Identity\UpdateStudentProfile.
            'entry_year' => ['prohibited'],
            'year_level' => ['sometimes', 'integer', 'between:1,4'],
            // Decided by the system, not chosen by Admission (ADR 0021, 0047).
            'enrollment_category' => ['prohibited'],
            'student_type' => ['sometimes', Rule::enum(StudentType::class)],
            'financial_status' => ['sometimes', 'nullable', Rule::enum(FinancialStatus::class)],
            'admission_status' => ['sometimes', Rule::enum(AdmissionStatus::class)],
            'curriculum_id' => ['prohibited'],
            'academic_standing' => ['prohibited'],
            'reason' => ['required', 'string', 'max:1000'],
            'identity_verified_in_person' => ['required', 'accepted'],
        ];
    }
}
