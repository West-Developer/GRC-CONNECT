<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Curriculum\ApplyCurriculumMigration;
use App\Actions\Curriculum\PreviewCurriculumMigration;
use App\Http\Controllers\Controller;
use App\Models\Curriculum;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurriculumMigrationController extends Controller
{
    /** @throws AuthenticationException */
    public function store(
        Request $request,
        Curriculum $curriculum,
        ApplyCurriculumMigration $migration,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }
        $this->authorize('update', $curriculum);
        $input = $request->validate([
            'student_id' => ['required', 'integer', 'exists:student_profiles,id'],
            'equivalency_ids' => ['present', 'array'],
            'equivalency_ids.*' => ['required', 'integer', 'distinct', 'exists:curriculum_subject_equivalencies,id'],
        ]);
        $student = StudentProfile::query()->whereKey($input['student_id'])->firstOrFail();
        $saved = $migration->execute(
            $actor,
            $curriculum,
            $student,
            array_values(array_map('intval', $input['equivalency_ids'])),
            $contextFactory->fromRequest($request),
        );

        $response = response()->json(['data' => [
            'id' => $saved->id,
            'student_id' => $saved->student_id,
            'source_curriculum_id' => $saved->source_curriculum_id,
            'target_curriculum_id' => $saved->target_curriculum_id,
            'credited_subject_ids' => $saved->credits->pluck('target_subject_id')->values()->all(),
            'migrated_at' => $saved->migrated_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ]], 201);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @throws AuthenticationException */
    public function preview(Request $request, Curriculum $curriculum, PreviewCurriculumMigration $preview): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }
        $this->authorize('update', $curriculum);
        $input = $request->validate(['student_number' => ['required', 'string', 'max:255']]);
        $student = StudentProfile::query()->where('student_number', $input['student_number'])->firstOrFail();
        $result = $preview->execute($actor, $curriculum, $student);

        $response = response()->json(['data' => [
            'student' => ['id' => $student->id, 'student_number' => $student->student_number],
            'source_curriculum_id' => $result['source_curriculum']->id,
            'target_curriculum_id' => $result['target_curriculum']->id,
            'credit_candidates' => array_map(static fn (array $candidate): array => [
                'equivalency_id' => $candidate['equivalency']->id,
                'source_subject' => ['id' => $candidate['equivalency']->sourceSubject->id, 'code' => $candidate['equivalency']->sourceSubject->code, 'title' => $candidate['equivalency']->sourceSubject->title],
                'target_subject' => ['id' => $candidate['equivalency']->targetSubject->id, 'code' => $candidate['equivalency']->targetSubject->code, 'title' => $candidate['equivalency']->targetSubject->title],
                'source_completion' => ['academic_grade_id' => $candidate['grade']->id, 'final_grade' => $candidate['grade']->final_grade ?? $candidate['grade']->mark?->value],
            ], $result['credit_candidates']),
        ]]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
