<?php

namespace App\Domain\Reports;

/**
 * The rows of one CHED / NSTP list (a page of it, or all of it) with the two counts the preview
 * warns about. The counts are over the whole list, not the page.
 */
final readonly class ChedReportRows
{
    /**
     * @param  list<array<string, int|string>>  $rows
     * @param  int  $total  students in the whole list
     * @param  int  $missingGender  listed students with no gender on file
     * @param  int  $demoPopulated  listed students carrying generated (demo) details
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $missingGender,
        public int $demoPopulated,
    ) {}
}
