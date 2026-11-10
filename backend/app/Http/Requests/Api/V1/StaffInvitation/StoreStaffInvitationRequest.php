<?php

namespace App\Http\Requests\Api\V1\StaffInvitation;

use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreStaffInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => [
                'required',
                Rule::in(array_map(
                    fn (UserRole $role): string => $role->value,
                    UserRole::registrarInvitableCases(),
                )),
            ],
            'college' => ['nullable', Rule::enum(CollegeCode::class)],
            'masters_degree' => ['nullable', 'string', 'max:255'],
        ];
    }
}
