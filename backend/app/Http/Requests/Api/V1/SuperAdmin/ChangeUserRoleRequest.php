<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $requiresCollege = in_array(
            $this->input('role'),
            [UserRole::Dean->value, UserRole::ProgramChair->value, UserRole::Faculty->value],
            true
        );

        return [
            'role' => [
                'required',
                Rule::in(array_map(
                    fn (UserRole $role): string => $role->value,
                    UserRole::superAdminInvitableCases(),
                )),
            ],
            'college' => $requiresCollege
                ? ['required', Rule::enum(CollegeCode::class)]
                : ['prohibited'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}