<?php

namespace App\Domain\Reports;

/**
 * A generated CHED / NSTP file waiting in the temp folder to be sent and deleted.
 */
final readonly class ChedReportFile
{
    public function __construct(
        public string $path,
        public string $downloadName,
        public string $contentType,
        public int $rows,
    ) {}
}
