<?php

namespace App\Actions\Billing;

use App\Models\FeeSchedule;

/**
 * The fee schedule as a flat, audit-safe map (ADR 0035): the tuition rate per
 * unit, and one `misc_fee_<id>` entry per miscellaneous fee holding its label,
 * amount, program codes, and whether it is inactive. Keys use the fee's id and
 * never its label, so a label can never trip the audit payload's forbidden-word
 * check, and the audit screen can still show each fee's old and new value.
 */
final class SnapshotFeeSchedule
{
    /**
     * @return array<string, string>
     */
    public static function current(): array
    {
        $snapshot = [];

        foreach (FeeSchedule::query()->orderBy('sort_order')->orderBy('id')->get() as $fee) {
            if ($fee->category === 'tuition') {
                $snapshot['tuition_rate_per_unit'] = number_format((float) $fee->amount, 2, '.', '');

                continue;
            }

            $codes = is_array($fee->program_codes) && $fee->program_codes !== []
                ? ' ['.implode(', ', $fee->program_codes).']'
                : '';
            $semester = $fee->semester !== null ? " ({$fee->semester} sem only)" : '';
            $snapshot["misc_fee_{$fee->id}"] = sprintf(
                '%s: %s%s%s%s',
                $fee->label,
                number_format((float) $fee->amount, 2, '.', ''),
                $codes,
                $semester,
                $fee->is_active ? '' : ' (inactive)',
            );
        }

        return $snapshot;
    }
}
