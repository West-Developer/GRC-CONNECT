<?php

namespace App\Domain\Enrollment;

use App\Models\Enrollment;
use App\Models\StudentProfile;

/**
 * What the Cashier's confirmation of a freshman's first payment did (ADR 0043).
 * `AWAITING_REGISTRAR`: the payment is in and the freshman is submitted to a block, waiting
 * for Registrar approval (the approval is what enrolls them, not the payment).
 * `AWAITING_SECTION`: no block could take them, so the payment is held as credit until a
 * section opens. `ENROLLED`: the Registrar has approved, so the enrollment is complete (only
 * ever seen on a repeat call). `created` is false when the confirmation had already been done.
 */
final readonly class FreshmanPaymentResult
{
    public const ENROLLED = 'enrolled';

    public const AWAITING_REGISTRAR = 'awaiting_registrar_approval';

    public const AWAITING_SECTION = 'awaiting_section';

    public function __construct(
        public string $outcome,
        public bool $created,
        public StudentProfile $profile,
        public ?Enrollment $enrollment,
        public ?string $blockCode,
        public string $amount,
    ) {}
}
