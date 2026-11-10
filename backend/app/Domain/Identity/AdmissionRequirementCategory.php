<?php

namespace App\Domain\Identity;

/**
 * Where an Admission requirement belongs (stakeholder Doc 14, ADR 0037): the
 * Freshman and Transferee lists match `StudentType`; Additional applies to
 * every student.
 */
enum AdmissionRequirementCategory: string
{
    case Freshman = 'freshman';
    case Transferee = 'transferee';
    case Additional = 'additional';

    public function label(): string
    {
        return match ($this) {
            self::Freshman => 'Freshman requirements',
            self::Transferee => 'Transferee requirements',
            self::Additional => 'Additional requirements',
        };
    }

    /**
     * The categories a student of this type is asked for, in display order.
     * An unset type (legacy rows) gets the Additional list only.
     *
     * @return list<self>
     */
    public static function forStudentType(?StudentType $type): array
    {
        return match ($type) {
            StudentType::Freshman => [self::Freshman, self::Additional],
            StudentType::Transferee => [self::Transferee, self::Additional],
            StudentType::Returnee => [self::Additional],
            // Assumption (not an approved policy): an existing student is asked for the Additional list only.
            StudentType::ExistingStudent => [self::Additional],
            null => [self::Additional],
        };
    }
}
