<?php

namespace App\Domain\Billing;

use App\Models\AssessmentItem;

/**
 * The amount a scholarship percentage is taken from (ADR 0025): the sum of an
 * assessment's tuition and miscellaneous lines, i.e. every line except an
 * existing discount line (scholarship or full-payment). Using this base — never the already discounted
 * total — is what keeps re-applying a tier from compounding.
 */
final class ScholarshipBase
{
    /**
     * @param  iterable<AssessmentItem>  $items
     * @return numeric-string
     */
    public static function amount(iterable $items): string
    {
        $base = '0.00';

        foreach ($items as $item) {
            if ($item->category->isDiscount()) {
                continue;
            }

            $base = bcadd($base, self::money($item->amount), 2);
        }

        return $base;
    }

    /**
     * @return numeric-string
     */
    private static function money(?string $amount): string
    {
        return is_numeric($amount) ? $amount : '0.00';
    }
}
