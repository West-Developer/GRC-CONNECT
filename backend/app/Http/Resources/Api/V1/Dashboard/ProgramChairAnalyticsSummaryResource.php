<?php

namespace App\Http\Resources\Api\V1\Dashboard;

use App\Domain\Analytics\AnalyticsYearOverYearPoint;
use App\Domain\Analytics\ProgramChairAnalyticsSummary;
use App\Domain\Analytics\RetentionBreakdownRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ProgramChairAnalyticsSummary $resource
 */
final class ProgramChairAnalyticsSummaryResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     academic_term_id: int,
     *     college: string,
     *     official_enrolled_count: int,
     *     year_level: ?int,
     *     enrollment_status_counts: array<string, int>,
     *     grade_status_counts: array<string, int>,
     *     retention_breakdown: list<array{grade_status: string, enrollment_status: string, count: int}>,
     *     year_over_year: list<array{school_year: string, semester: string, enrollee_count: int, stopped_count: int, attrition_rate: float}>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'program_chair_analytics_summary',
            'academic_term_id' => $this->resource->academicTermId,
            'college' => $this->resource->college,
            'official_enrolled_count' => $this->resource->officialEnrolledCount,
            'year_level' => $this->resource->yearLevel,
            'enrollment_status_counts' => $this->resource->enrollmentStatusCounts,
            'grade_status_counts' => $this->resource->gradeStatusCounts,
            'retention_breakdown' => array_map(
                fn (RetentionBreakdownRow $row): array => [
                    'grade_status' => $row->gradeStatus,
                    'enrollment_status' => $row->enrollmentStatus,
                    'count' => $row->count,
                ],
                $this->resource->retentionBreakdown,
            ),
            'year_over_year' => array_map(
                fn (AnalyticsYearOverYearPoint $point): array => [
                    'school_year' => $point->schoolYear,
                    'semester' => $point->semester,
                    'enrollee_count' => $point->enrolleeCount,
                    'stopped_count' => $point->stoppedCount,
                    'attrition_rate' => $point->attritionRate,
                ],
                $this->resource->yearOverYear,
            ),
        ];
    }
}
