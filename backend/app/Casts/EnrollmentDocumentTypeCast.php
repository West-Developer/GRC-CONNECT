<?php

namespace App\Casts;

use App\Domain\Enrollment\EnrollmentDocumentType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps existing COM rows readable while the reversible database migration
 * converts them to COR. The application always exposes and persists the one
 * canonical COR type; it never reintroduces COM as a domain value.
 *
 * @implements CastsAttributes<EnrollmentDocumentType, EnrollmentDocumentType|string>
 */
final class EnrollmentDocumentTypeCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?EnrollmentDocumentType
    {
        if ($value === null) {
            return null;
        }

        if ($value === 'com') {
            return EnrollmentDocumentType::Cor;
        }

        return EnrollmentDocumentType::from($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $type = $value instanceof EnrollmentDocumentType
            ? $value
            : EnrollmentDocumentType::from($value);

        return $type->value;
    }
}
