<?php

namespace App\Actions\Faculty;

use App\Domain\Academic\GradeStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\User;

/**
 * The Registrar Head's read-only view of one professor (stakeholder Doc 14):
 * who they are, what they have taught term by term, and, for one term, each
 * section with its schedule, seats, and how far grade submission has got.
 * Counts only: no student is named anywhere in the result.
 */
final class BuildFacultyProfile
{
    /**
     * @return array<string, mixed>
     */
    public function execute(User $professor, ?AcademicTerm $term): array
    {
        $sections = Section::query()
            ->with(['subject:id,code,title,units', 'academicTerm:id,school_year,semester,status'])
            ->where('professor_id', $professor->id)
            ->orderBy('academic_term_id')
            ->orderBy('subject_id')
            ->orderBy('section_code')
            ->get();

        $terms = $sections->groupBy('academic_term_id')->map(function ($termSections): array {
            /** @var Section $first */
            $first = $termSections->first();

            return [
                'academic_term_id' => $first->academic_term_id,
                'label' => self::termLabel($first->academicTerm),
                'sections_count' => $termSections->count(),
                'total_units' => self::units($termSections),
            ];
        })->sortByDesc('academic_term_id')->values()->all();

        $selected = $term ?? $this->defaultTerm($sections->map(fn (Section $section): AcademicTerm => $section->academicTerm)->unique('id')->values()->all());
        $termSections = $selected === null
            ? collect()
            : $sections->where('academic_term_id', $selected->id)->values();

        $gradeCounts = $termSections->isEmpty()
            ? collect()
            : AcademicGrade::query()
                ->whereIn('section_id', $termSections->pluck('id'))
                ->selectRaw('section_id, status, count(*) as aggregate')
                ->groupBy('section_id', 'status')
                ->get()
                ->groupBy('section_id');

        $sectionRows = $termSections->map(function (Section $section) use ($gradeCounts): array {
            $counts = [GradeStatus::Draft->value => 0, GradeStatus::Submitted->value => 0, GradeStatus::Locked->value => 0];
            foreach ($gradeCounts->get($section->id, collect()) as $row) {
                $counts[$row->status->value] = (int) $row->getAttribute('aggregate');
            }

            return [
                'section_id' => $section->id,
                'section_code' => $section->section_code,
                'subject_code' => $section->subject->code,
                'subject_title' => $section->subject->title,
                'units' => (float) $section->subject->units,
                'schedule_days' => $section->schedule_days,
                'starts_at_time' => $section->starts_at_time,
                'ends_at_time' => $section->ends_at_time,
                'room' => $section->room,
                'modality' => $section->modality?->value,
                'status' => $section->status->value,
                'enrolled_count' => $section->enrolled_count,
                'capacity' => $section->capacity,
                'grades' => $counts + ['total' => array_sum($counts)],
            ];
        })->all();

        return [
            'professor' => [
                'id' => $professor->id,
                'name' => $professor->name,
                'college' => $professor->college?->value,
                'status' => $professor->status->value,
                'employment_type' => $professor->employment_type?->value,
                'employment_type_label' => $professor->employment_type?->label(),
                'masters_degree' => $professor->masters_degree,
            ],
            'terms' => $terms,
            'selected_term' => $selected === null ? null : [
                'academic_term_id' => $selected->id,
                'label' => self::termLabel($selected),
                'total_units' => self::units($termSections),
                'sections' => $sectionRows,
            ],
        ];
    }

    /**
     * The ongoing term if the professor teaches in it, otherwise their most
     * recent term.
     *
     * @param  array<int, AcademicTerm>  $terms  the terms the professor has sections in
     */
    private function defaultTerm(array $terms): ?AcademicTerm
    {
        if ($terms === []) {
            return null;
        }

        foreach ($terms as $term) {
            if ($term->status === AcademicTermStatus::SemesterOngoing) {
                return $term;
            }
        }

        usort($terms, fn (AcademicTerm $a, AcademicTerm $b): int => $b->id <=> $a->id);

        return $terms[0];
    }

    private static function termLabel(AcademicTerm $term): string
    {
        return "{$term->school_year} · {$term->semester}";
    }

    /**
     * @param  iterable<Section>  $sections
     */
    private static function units(iterable $sections): float
    {
        $total = 0.0;
        foreach ($sections as $section) {
            $total += (float) $section->subject->units;
        }

        return $total;
    }
}
