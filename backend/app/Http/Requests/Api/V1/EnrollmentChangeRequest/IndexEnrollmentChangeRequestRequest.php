<?php

namespace App\Http\Requests\Api\V1\EnrollmentChangeRequest;

use App\Domain\Enrollment\EnrollmentChangeRequestStatus;
use App\Domain\Enrollment\EnrollmentChangeRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexEnrollmentChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(array_map(
                fn (EnrollmentChangeRequestStatus $status): string => $status->value,
                EnrollmentChangeRequestStatus::cases(),
            ))],
            'type' => ['sometimes', Rule::in(array_map(
                fn (EnrollmentChangeRequestType $type): string => $type->value,
                EnrollmentChangeRequestType::cases(),
            ))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
