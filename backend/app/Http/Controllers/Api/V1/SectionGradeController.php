<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Academic\BuildSectionGradeSheet;
use App\Actions\Academic\ListFacultyGradeSections;
use App\Actions\Academic\SaveSectionGradeDrafts;
use App\Actions\Academic\SubmitSectionGrades;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SectionGrade\IndexSectionGradeSubmissionRequest;
use App\Http\Requests\Api\V1\SectionGrade\ShowSectionGradesRequest;
use App\Http\Requests\Api\V1\SectionGrade\StoreSectionGradeDraftsRequest;
use App\Http\Requests\Api\V1\SectionGrade\SubmitSectionGradesRequest;
use App\Http\Resources\Api\V1\SectionGradeSheetResource;
use App\Http\Resources\Api\V1\SectionGradeSummaryResource;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class SectionGradeController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(
        IndexSectionGradeSubmissionRequest $request,
        ListFacultyGradeSections $listFacultyGradeSections,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('view-section-grade-submission');

        $response = SectionGradeSummaryResource::collection(
            $listFacultyGradeSections->execute($actor),
        )->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @throws AuthenticationException
     */
    public function show(
        ShowSectionGradesRequest $request,
        Section $section,
        BuildSectionGradeSheet $buildSectionGradeSheet,
    ): JsonResponse {
        if (! $request->user() instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('viewGrades', $section);

        $response = SectionGradeSheetResource::make(
            $buildSectionGradeSheet->execute($section),
        )->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreSectionGradeDraftsRequest $request,
        Section $section,
        SaveSectionGradeDrafts $saveSectionGradeDrafts,
        BuildSectionGradeSheet $buildSectionGradeSheet,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('updateGrades', $section);
        /** @var list<array{student_id: int, mark: string, remarks?: ?string}> $grades */
        $grades = $request->validated('grades');
        $saveSectionGradeDrafts->execute($section, $grades, $actor, $contextFactory->fromRequest($request));

        $response = SectionGradeSheetResource::make(
            $buildSectionGradeSheet->execute($section->refresh()),
        )->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @throws AuthenticationException
     */
    public function submit(
        SubmitSectionGradesRequest $request,
        Section $section,
        SubmitSectionGrades $submitSectionGrades,
        BuildSectionGradeSheet $buildSectionGradeSheet,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $this->authorize('submitGrades', $section);
        $submitSectionGrades->execute($section, $actor, $contextFactory->fromRequest($request));

        $response = SectionGradeSheetResource::make(
            $buildSectionGradeSheet->execute($section->refresh()),
        )->response($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
