<?php

namespace App\Domain\Identity;

use App\Domain\Enrollment\EnrollmentCategory;

/**
 * Stakeholder Doc 17: at Admission intake (and only while a profile's
 * academic-setup fields are still editable), Year Level 1 defaults to
 * Regular/Freshman and Year Levels 2-4 default to Irregular/Transferee —
 * the client can no longer choose these two fields directly.
 *
 * This is a provisioning default, never a derivation: it is unrelated to
 * ADR 0021's `ReclassifyStudentEnrollmentCategory`, which independently
 * re-derives `enrollment_category` from grade history every term and must
 * keep treating a value set here as an ordinary default
 * (`enrollment_category_derived_at` stays NULL when this class is used).
 */
final class AdmissionIntakeDefaults
{
    public static function enrollmentCategoryFor(int $yearLevel): EnrollmentCategory
    {
        return $yearLevel === 1 ? EnrollmentCategory::Regular : EnrollmentCategory::Irregular;
    }

    public static function studentTypeFor(int $yearLevel): StudentType
    {
        return $yearLevel === 1 ? StudentType::Freshman : StudentType::Transferee;
    }
}
