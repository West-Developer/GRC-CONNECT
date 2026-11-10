<?php

namespace App\Console\Commands;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\EnrollmentRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AuditEnrollmentRevisionsCommand extends Command
{
    protected $signature = 'enrollments:audit-revisions {--limit=200 : Maximum finding rows to display}';

    protected $description = 'Report inconsistent pending revisions without changing records or exposing student details';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Limit must be an integer between 1 and 10000.');

            return self::INVALID;
        }
        $checked = 0;
        $affected = 0;
        $counts = [];
        $findings = [];
        $query = EnrollmentRevision::query()->select('enrollment_revisions.*')
            ->where('status', EnrollmentRevision::STATUS_PENDING)
            ->with('enrollment.student')
            ->selectSub(DB::table('enrollment_revisions as later')->selectRaw('1')
                ->whereColumn('later.enrollment_id', 'enrollment_revisions.enrollment_id')
                ->whereColumn('later.id', '>', 'enrollment_revisions.id')->limit(1), 'has_newer_revision');

        foreach ($query->lazyById(200) as $revision) {
            $checked++;
            $issues = [];
            if ($revision->proposed_by === null) {
                $issues[] = 'unknown_author';
            } else {
                $expected = $revision->proposed_by === $revision->enrollment->student->user_id
                    ? EnrollmentStatus::PendingProgramHeadApproval : EnrollmentStatus::PendingStudentReview;
                if ($revision->enrollment->status !== $expected) {
                    $issues[] = 'enrollment_not_waiting_for_proposal';
                }
            }
            if ($revision->responded_at !== null) {
                $issues[] = 'pending_with_response';
            }
            if ($revision->getAttribute('has_newer_revision') !== null) {
                $issues[] = 'newer_revision_exists';
            }
            if ($issues === []) {
                continue;
            }
            $affected++;
            foreach ($issues as $issue) {
                $counts[$issue] = ($counts[$issue] ?? 0) + 1;
            }
            if (count($findings) < $limit) {
                $findings[] = ['revision_id' => $revision->id, 'enrollment_id' => $revision->enrollment_id, 'issues' => $issues];
            }
        }
        $this->line(json_encode([
            'read_only' => true, 'pending_checked' => $checked, 'affected' => $affected,
            'issue_counts' => $counts, 'truncated' => $affected > count($findings), 'findings' => $findings,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
