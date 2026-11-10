<?php

namespace App\Domain\Dashboard;

/**
 * Deliberately minimal: only `studentNumber` identifies the student — never
 * name, email, or any other contact field. PRD §3.5 authorizes the Dean to
 * see "stuck-student reports"; it does not authorize the Dean to browse full
 * enrollment or student records, which is why this stays a purpose-built row
 * shape rather than reusing EnrollmentResource.
 */
final readonly class StuckEnrollmentRow
{
    public function __construct(
        public int $enrollmentId,
        public string $studentNumber,
        public string $status,
        public string $statusLabel,
        public int $daysInStatus,
        public bool $isFlagged,
    ) {}
}
