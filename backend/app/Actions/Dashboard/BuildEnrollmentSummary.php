<?php

namespace App\Actions\Dashboard;

use App\Domain\Academic\GradeStatus;
use App\Domain\Dashboard\EnrollmentSummary;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use Illuminate\Support\Facades\DB;

/**
 * The first SQL-aggregation Action in this codebase (see ADR 0017) — every
 * other Action so far returns a paginator or a single model. Grouped
 * strictly by EnrollmentStatus and GradeStatus, the two PRD-authoritative
 * enums (see their own docblocks) — never by a raw string literal, since
 * SectionStatus and every other status column here is explicitly marked
 * provisional pending a future data migration. `payments.amount` is never
 * touched; only row counts are computed.
 */
final readonly class BuildEnrollmentSummary
{
    public function execute(AcademicTerm $term): EnrollmentSummary
    {
        $statusRows = DB::table('enrollments')
            ->where('academic_term_id', $term->id)
            ->select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statusCounts = [];
        foreach (EnrollmentStatus::cases() as $status) {
            $statusCounts[$status->value] = (int) ($statusRows[$status->value] ?? 0);
        }

        $funnelRow = DB::table('enrollments')
            ->where('academic_term_id', $term->id)
            ->selectRaw('count(case when submitted_at is not null then 1 end) as submitted')
            ->selectRaw('count(case when registrar_decided_at is not null then 1 end) as registrar_decided')
            ->selectRaw('count(case when payment_confirmed_at is not null then 1 end) as payment_confirmed')
            ->selectRaw('count(case when enrolled_at is not null then 1 end) as enrolled')
            ->first();

        $funnelCounts = [
            'submitted' => (int) ($funnelRow->submitted ?? 0),
            'registrar_decided' => (int) ($funnelRow->registrar_decided ?? 0),
            'payment_confirmed' => (int) ($funnelRow->payment_confirmed ?? 0),
            'enrolled' => (int) ($funnelRow->enrolled ?? 0),
        ];

        $sectionRow = DB::table('sections')
            ->where('academic_term_id', $term->id)
            ->selectRaw('count(*) as total')
            ->selectRaw('count(case when status = ? then 1 end) as published', [SectionStatus::Published->value])
            ->selectRaw('sum(case when status = ? then capacity else 0 end) as capacity', [SectionStatus::Published->value])
            ->selectRaw('sum(case when status = ? then enrolled_count else 0 end) as enrolled_seats', [SectionStatus::Published->value])
            ->first();

        $gradeRows = DB::table('academic_grades')
            ->where('academic_term_id', $term->id)
            ->select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $gradeStatusCounts = [];
        foreach (GradeStatus::cases() as $status) {
            $gradeStatusCounts[$status->value] = (int) ($gradeRows[$status->value] ?? 0);
        }

        return new EnrollmentSummary(
            academicTermId: $term->id,
            statusCounts: $statusCounts,
            funnelCounts: $funnelCounts,
            totalSections: (int) ($sectionRow->total ?? 0),
            publishedSections: (int) ($sectionRow->published ?? 0),
            totalCapacity: (int) ($sectionRow->capacity ?? 0),
            totalEnrolledSeats: (int) ($sectionRow->enrolled_seats ?? 0),
            gradeStatusCounts: $gradeStatusCounts,
        );
    }
}
