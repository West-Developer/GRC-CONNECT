<?php

namespace App\Domain\Enrollment;

/**
 * The outcome of `App\Actions\Academic\ClassifyEnrollmentStanding::classify()`
 * — mirrors the shape of `App\Domain\Academic\PrerequisiteVerdict`: a
 * category plus the explainable reasons behind it, never a silent flag flip.
 */
final readonly class ClassificationVerdict
{
    /**
     * @param  list<array{code: string, message: string}>  $reasons
     */
    private function __construct(
        public EnrollmentCategory $category,
        public array $reasons,
    ) {}

    public static function regular(): self
    {
        return new self(EnrollmentCategory::Regular, []);
    }

    /**
     * @param  list<array{code: string, message: string}>  $reasons
     */
    public static function irregular(array $reasons): self
    {
        return new self(EnrollmentCategory::Irregular, $reasons);
    }

    public function isRegular(): bool
    {
        return $this->category === EnrollmentCategory::Regular;
    }
}
