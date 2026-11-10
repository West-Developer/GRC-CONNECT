<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\AdmissionRequirementCategory;
use App\Models\AdmissionRequirementType;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a requirement to the shared Admission catalogue (Admission Staff; ADR
 * 0037). It appears on every student of that category. A name already in the
 * category is refused rather than duplicated.
 */
final readonly class CreateAdmissionRequirementType
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(
        string $name,
        AdmissionRequirementCategory $category,
        User $actor,
        AuditRequestContext $context,
    ): AdmissionRequirementType {
        $name = trim($name);

        return DB::transaction(function () use ($name, $category, $actor, $context): AdmissionRequirementType {
            $exists = AdmissionRequirementType::query()
                ->where('category', $category->value)
                ->where('name', $name)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'name' => 'That requirement is already on the list for this category.',
                ]);
            }

            $nextOrder = (int) AdmissionRequirementType::query()
                ->where('category', $category->value)
                ->max('sort_order') + 10;

            $type = AdmissionRequirementType::create([
                'category' => $category,
                'name' => $name,
                'sort_order' => $nextOrder,
                'is_active' => true,
                'is_system' => false,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::ADMISSION_REQUIREMENT_TYPE_CREATED,
                AuditableType::ADMISSION_REQUIREMENT_TYPE,
                $type->id,
                null,
                ['requirement' => $type->name, 'category' => $category->value],
                null,
                $context,
            );

            return $type;
        });
    }
}
