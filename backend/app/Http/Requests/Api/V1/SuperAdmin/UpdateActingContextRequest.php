<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Domain\Identity\ActingContext;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class UpdateActingContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $switchableRoles = array_map(
            static fn (UserRole $role): string => $role->value,
            UserRole::superAdminSwitchableCases()
        );

        return [
            'role' => ['required', 'string', Rule::in($switchableRoles)],
            'college' => ['nullable', 'string', Rule::enum(CollegeCode::class)],
        ];
    }

    protected function passedValidation(): void
    {
        $role = UserRole::tryFrom((string) $this->input('role'));
        if ($role === null) {
            return;
        }

        $college = $this->filled('college') ? CollegeCode::tryFrom((string) $this->input('college')) : null;

        try {
            ActingContext::fromRequest($role, $college);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'college' => [$e->getMessage()],
            ]);
        }
    }

    public function actingContext(): ActingContext
    {
        $role = UserRole::from((string) $this->input('role'));
        $college = $this->filled('college') ? CollegeCode::from((string) $this->input('college')) : null;

        return ActingContext::fromRequest($role, $college);
    }
}
