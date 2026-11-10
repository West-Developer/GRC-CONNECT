<?php

namespace App\Actions\Academic;

use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListFacultyGradeSections
{
    public function __construct(private BuildSectionGradeSummary $summaryBuilder) {}

    /**
     * @return Collection<int, Section>
     */
    public function execute(User $actor): Collection
    {
        $sections = Section::query()
            ->visibleTo($actor)
            ->with(['academicTerm', 'subject'])
            ->orderByDesc('academic_term_id')
            ->orderBy('subject_id')
            ->orderBy('section_code')
            ->get();

        foreach ($sections as $section) {
            $section->setAttribute('grade_progress', $this->summaryBuilder->execute($section));
        }

        return $sections;
    }
}
