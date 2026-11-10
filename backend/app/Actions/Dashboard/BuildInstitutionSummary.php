<?php

namespace App\Actions\Dashboard;

use App\Domain\Dashboard\InstitutionSummary;
use App\Domain\Dashboard\YearOverYearCount;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Institution-wide counterpart to BuildEnrollmentSummary — no term filter.
 * Year-over-year grouping uses `academic_terms.school_year`, which PRD §3.6
 * ("View... KPIs and year-over-year comparisons") makes structurally
 * possible today via that column's own UNIQUE(school_year, semester)
 * constraint, without inventing any new institutional value.
 */
final readonly class BuildInstitutionSummary
{
    public function execute(?int $academicTermId = null): InstitutionSummary
    {
        $statusRows = DB::table('enrollments')
            ->when($academicTermId !== null, fn ($query) => $query->where('academic_term_id', $academicTermId))
            ->select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statusCounts = [];
        foreach (EnrollmentStatus::cases() as $status) {
            $statusCounts[$status->value] = (int) ($statusRows[$status->value] ?? 0);
        }

        $programRow = DB::table('programs')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(case when status = ? then 1 end) as active', [ProgramStatus::Active->value])
            ->first();

        $sectionRow = DB::table('sections')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(case when status = ? then 1 end) as published', [SectionStatus::Published->value])
            ->first();

        $programCounts = DB::table('student_profiles')
            ->join('programs', 'programs.id', '=', 'student_profiles.program_id')
            ->select('programs.code')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('programs.code')
            ->pluck('aggregate', 'code')
            ->map(fn ($count) => (int) $count)
            ->all();

        $yearOverYearRows = DB::table('enrollments')
            ->join('academic_terms', 'academic_terms.id', '=', 'enrollments.academic_term_id')
            ->select('academic_terms.school_year')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('academic_terms.school_year')
            ->orderBy('academic_terms.school_year')
            ->get();

        $yearOverYear = array_values($yearOverYearRows
            ->map(fn ($row): YearOverYearCount => new YearOverYearCount(
                schoolYear: (string) $row->school_year,
                enrollmentCount: (int) $row->aggregate,
            ))
            ->all());

        return new InstitutionSummary(
            statusCounts: $statusCounts,
            totalPrograms: (int) ($programRow->total ?? 0),
            activePrograms: (int) ($programRow->active ?? 0),
            totalSections: (int) ($sectionRow->total ?? 0),
            publishedSections: (int) ($sectionRow->published ?? 0),
            programCounts: $programCounts,
            yearOverYear: $yearOverYear,
        );
    }
}
