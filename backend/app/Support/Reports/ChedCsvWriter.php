<?php

namespace App\Support\Reports;

use App\Domain\Reports\ChedReport;
use RuntimeException;

/**
 * The CSV twin of the CHED / NSTP Excel files (ADR 0049): the template's column headers on the first
 * line, one line per student. UTF-8 with a byte-order mark so Excel reads accents, CRLF line ends.
 *
 * Student-supplied text that a spreadsheet would run as a formula (`=`, `@`, a tab or return, or a
 * `+` / `-` that is not a phone-number or numeric shape) gets a leading apostrophe, so opening the
 * CSV in Excel can never execute a name.
 */
final class ChedCsvWriter
{
    private const COMPONENTS = ['rotc' => 'ROTC', 'cwts' => 'CWTS', 'lts' => 'LTS'];

    /**
     * @param  list<array<string, int|string>>  $rows
     */
    public function writeList(ChedReport $report, array $rows, string $path): void
    {
        $columns = $report->columns();
        $lines = [array_column($columns, 'label')];

        foreach ($rows as $row) {
            $lines[] = array_map(
                fn (array $column): string => $this->safe((string) ($row[$column['key']] ?? '')),
                $columns,
            );
        }

        $this->write($path, $lines);
    }

    /**
     * @param  array{
     *     campus: string, classification: string, academic_year: string,
     *     enrollment: array<string, array<string, array{m: int, f: int}>>,
     *     graduates: array<string, array{m: int, f: int}>,
     * }  $summary
     */
    public function writeSummary(array $summary, string $path): void
    {
        $labels = ['NAME OF HEI/CAMPUS', 'Classification (Private/Public)'];
        $values = [$summary['campus'], $summary['classification']];

        $groups = [
            'Enrollment 1st Sem' => $summary['enrollment']['1st'],
            'Enrollment 2nd Sem' => $summary['enrollment']['2nd'],
            'Enrollment Summer' => $summary['enrollment']['summer'],
            'Graduates' => $summary['graduates'],
        ];

        foreach ($groups as $group => $components) {
            foreach (self::COMPONENTS as $key => $component) {
                foreach (['m' => 'M', 'f' => 'F'] as $genderKey => $gender) {
                    $labels[] = "{$group} {$component} {$gender}";
                    $values[] = (string) $components[$key][$genderKey];
                }
            }
        }

        $total = array_merge(['TOTAL', '1'], array_slice($values, 2));

        $this->write($path, [$labels, array_map(fn (string $value): string => $this->safe($value), $values), $total]);
    }

    /**
     * @param  list<list<string>>  $lines
     */
    private function write(string $path, array $lines): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('The CSV file could not be created.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($lines as $line) {
            fputcsv($handle, $line, ',', '"', '', "\r\n");
        }
        fclose($handle);
    }

    private function safe(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $first = $value[0];

        if (in_array($first, ['=', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        if (($first === '+' || $first === '-') && preg_match('/^[+-]?[\d\s().-]+$/', $value) !== 1) {
            return "'".$value;
        }

        return $value;
    }
}
