<?php

namespace App\Domain\Enrollment;

/**
 * Generated enrollment artifacts.
 *
 * GRC confirmed that COM and COR are the same artifact. The enum deliberately
 * remains single-valued so one enrollment has exactly one Certificate of
 * Registration rather than two overlapping documents.
 */
enum EnrollmentDocumentType: string
{
    case Cor = 'cor';

    public function label(): string
    {
        return match ($this) {
            self::Cor => 'Certificate of Registration',
        };
    }
}
