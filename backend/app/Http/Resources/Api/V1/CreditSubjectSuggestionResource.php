<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Academic\CreditSubjectSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CreditSubjectSuggestion $resource
 */
final class CreditSubjectSuggestionResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     subject_id: int,
     *     subject_code: string,
     *     subject_title: string,
     *     units: float,
     *     year_level: int,
     *     semester: string,
     *     score: float,
     *     reasons: list<string>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'credit_subject_suggestion',
            'subject_id' => $this->resource->subjectId,
            'subject_code' => $this->resource->subjectCode,
            'subject_title' => $this->resource->subjectTitle,
            'units' => $this->resource->units,
            'year_level' => $this->resource->yearLevel,
            'semester' => $this->resource->semester,
            'score' => $this->resource->score,
            'reasons' => $this->resource->reasons,
        ];
    }
}
