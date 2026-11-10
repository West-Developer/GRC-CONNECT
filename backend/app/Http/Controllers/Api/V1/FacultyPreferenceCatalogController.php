<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Http\Controllers\Controller;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** The faculty's own college-scoped New/Old curriculum subject catalog. */
final class FacultyPreferenceCatalogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }
        if ($user->college === null) {
            return $this->privateResponse(response()->json(['data' => []]));
        }

        $curricula = Curriculum::query()
            ->with(['program', 'subjectPlacements.subject'])
            ->whereHas('program', fn ($query) => $query->where('college', $user->college->value))
            ->whereIn('status', [CurriculumStatus::Active->value, CurriculumStatus::Archived->value])
            ->orderBy('program_id')
            ->orderByDesc('effective_start_year')
            ->get()
            ->groupBy('program_id')
            ->flatMap(static fn ($versions) => $versions->take(2))
            ->values();

        $data = $curricula->groupBy('program_id')->flatMap(static function ($versions): array {
            return $versions->values()->map(static function (Curriculum $curriculum, int $index): array {
                $placements = $curriculum->subjectPlacements
                    ->filter(static fn ($placement): bool => ! empty($placement->subject_id))
                    ->sortBy(static fn ($placement): string => $placement->subject->code);

                return [
                    'curriculum_id' => $curriculum->id,
                    'program_id' => $curriculum->program_id,
                    'program_code' => $curriculum->program->code,
                    'program_name' => $curriculum->program->name,
                    'curriculum_name' => $curriculum->name,
                    'effective_school_year' => $curriculum->effective_school_year,
                    'version_label' => $index === 0 ? 'new' : 'old',
                    'semesters' => [
                        ['semester' => '1st', 'subjects' => self::subjectsForSemester($placements, '1st')],
                        ['semester' => '2nd', 'subjects' => self::subjectsForSemester($placements, '2nd')],
                    ],
                ];
            })->all();
        })->values()->all();

        return $this->privateResponse(response()->json(['data' => $data]));
    }

    /**
     * @param  Collection<int, CurriculumSubject>  $placements
     * @return list<array{id: int, code: string, title: string, units: float, paired_subject_id: ?int}>
     */
    private static function subjectsForSemester(Collection $placements, string $semester): array
    {
        /** @var list<array{id: int, code: string, title: string, units: float, paired_subject_id: ?int, is_lecture: bool}> $raw */
        $raw = array_values($placements
            ->filter(static fn ($placement): bool => in_array($placement->semester, [$semester, '1st|2nd'], true))
            ->map(static fn ($placement): array => [
                'id' => $placement->subject->id,
                'code' => $placement->subject->code,
                'title' => $placement->subject->title,
                'units' => (float) $placement->subject->units,
                'paired_subject_id' => $placement->subject->paired_subject_id,
                'is_lecture' => $placement->subject->isLectureComponent(),
            ])
            ->unique('id')
            ->values()
            ->all());

        return self::groupPairedCatalogSubjects($raw);
    }

    /**
     * @param  list<array{id: int, code: string, title: string, units: float, paired_subject_id: ?int, is_lecture: bool}>  $subjects
     * @return list<array{id: int, code: string, title: string, units: float, paired_subject_id: ?int}>
     */
    private static function groupPairedCatalogSubjects(array $subjects): array
    {
        $byId = [];
        foreach ($subjects as $s) {
            $byId[$s['id']] = $s;
        }

        $placed = [];
        $grouped = [];

        foreach ($subjects as $s) {
            $id = $s['id'];
            if (isset($placed[$id])) {
                continue;
            }

            $pairedId = $s['paired_subject_id'];
            $partner = $pairedId !== null ? ($byId[$pairedId] ?? null) : null;

            if ($partner !== null && ! isset($placed[$partner['id']])) {
                if ($s['is_lecture']) {
                    $grouped[] = self::stripCatalogSubject($s);
                    $placed[$id] = true;
                    $grouped[] = self::stripCatalogSubject($partner);
                    $placed[$partner['id']] = true;
                } else {
                    $grouped[] = self::stripCatalogSubject($partner);
                    $placed[$partner['id']] = true;
                    $grouped[] = self::stripCatalogSubject($s);
                    $placed[$id] = true;
                }

                continue;
            }

            $grouped[] = self::stripCatalogSubject($s);
            $placed[$id] = true;
        }

        return $grouped;
    }

    /**
     * @param  array{id: int, code: string, title: string, units: float, paired_subject_id: ?int, is_lecture: bool}  $subject
     * @return array{id: int, code: string, title: string, units: float, paired_subject_id: ?int}
     */
    private static function stripCatalogSubject(array $subject): array
    {
        return [
            'id' => $subject['id'],
            'code' => $subject['code'],
            'title' => $subject['title'],
            'units' => $subject['units'],
            'paired_subject_id' => $subject['paired_subject_id'],
        ];
    }

    private function privateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
