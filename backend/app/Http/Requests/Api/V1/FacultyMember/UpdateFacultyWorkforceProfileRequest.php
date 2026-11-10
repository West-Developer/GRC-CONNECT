<?php

namespace App\Http\Requests\Api\V1\FacultyMember;

use App\Domain\Identity\FacultyEmploymentType;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFacultyWorkforceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(UserStatus::class)],
            'employment_type' => ['required', Rule::enum(FacultyEmploymentType::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $faculty = $this->route('facultyMember');
            if (! $faculty instanceof User) {
                return;
            }
            if ($faculty->status === UserStatus::Active
                && $this->input('status') === UserStatus::Disabled->value
                && trim((string) $this->input('reason')) === '') {
                $validator->errors()->add('reason', 'Provide a reason when making an active faculty account inactive.');
            }
        });
    }
}
