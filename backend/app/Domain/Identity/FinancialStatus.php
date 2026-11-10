<?php

namespace App\Domain\Identity;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * Whether a student is a fee-paying Payee or a Scholar. The value itself is a
 * label shown to Registrar/Accounting staff (and on the COR); it never drives a
 * fee computation: `App\Domain\Billing\AssessmentComputation` reads only total
 * units and the fee schedule. A scholarship's *discount* is not derived from
 * this value: the Cashier assigns it per enrollment at payment time as an
 * explicit 100%/40%/20% assessment line (ADR 0025, `ScholarshipTier`), which
 * also sets this to Scholar (or back to Payee when it is removed). The wider
 * scholarship-waiver policy stays a PRD §17 open item.
 */
enum FinancialStatus: string
{
    case Scholar = 'scholar';
    case Payee = 'payee';

    public function label(): string
    {
        return match ($this) {
            self::Scholar => 'Scholar',
            self::Payee => 'Payee',
        };
    }
}
