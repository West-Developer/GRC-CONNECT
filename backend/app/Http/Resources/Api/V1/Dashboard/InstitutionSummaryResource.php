<?php

namespace App\Http\Resources\Api\V1\Dashboard;

use App\Domain\Dashboard\InstitutionSummary;
use App\Domain\Dashboard\YearOverYearCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read InstitutionSummary $resource
 */
final class InstitutionSummaryResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     status_counts: array<string, int>,
     *     total_programs: int,
     *     active_programs: int,
     *     total_sections: int,
     *     published_sections: int,
     *     program_counts: array<string, int>,
     *     year_over_year: list<array{school_year: string, enrollment_count: int}>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'institution_summary',
            'status_counts' => $this->resource->statusCounts,
            'total_programs' => $this->resource->totalPrograms,
            'active_programs' => $this->resource->activePrograms,
            'total_sections' => $this->resource->totalSections,
            'published_sections' => $this->resource->publishedSections,
            'program_counts' => $this->resource->programCounts,
            'year_over_year' => array_map(
                fn (YearOverYearCount $entry): array => [
                    'school_year' => $entry->schoolYear,
                    'enrollment_count' => $entry->enrollmentCount,
                ],
                $this->resource->yearOverYear,
            ),
        ];
    }
}
