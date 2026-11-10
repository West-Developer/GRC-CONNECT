<?php

namespace App\Http\Requests\Api\V1\AuditLog;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAuditActorRequest extends FormRequest
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
            'action' => ['sometimes', Rule::in(AuditAction::values())],
            'auditable_type' => ['sometimes', Rule::in(AuditableType::values())],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
