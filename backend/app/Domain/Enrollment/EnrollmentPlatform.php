<?php

namespace App\Domain\Enrollment;

/**
 * How classes are delivered for a term's enrollees, as printed on the
 * Certificate of Registration (stakeholder Doc 14). Students have no platform
 * of their own, so the Registrar Head sets one value for the whole enrolling
 * population of a term (`academic_terms.enrollment_platform`); it is not the
 * per-section `SectionModality` (HyFlex / F2F). `Both` is for a term where
 * classes meet face-to-face and online.
 */
enum EnrollmentPlatform: string
{
    case Online = 'online';
    case FaceToFace = 'face_to_face';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::FaceToFace => 'Face-to-Face',
            self::Both => 'Both (Face-to-Face and Online)',
        };
    }
}
