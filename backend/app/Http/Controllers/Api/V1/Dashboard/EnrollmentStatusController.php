<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Dashboard\BuildEnrollmentStatusOverview;
use App\Actions\Dashboard\BuildEnrollmentStatusSections;
use App\Actions\Dashboard\ListEnrollmentStatusStudents;
use App\Actions\Dashboard\ShowEnrollmentStatusStudent;
use App\Domain\Dashboard\EnrollmentStatusGroup;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\IndexDashboardRequest;
use App\Http\Requests\Api\V1\Dashboard\IndexEnrollmentStatusSectionsRequest;
use App\Http\Requests\Api\V1\Dashboard\IndexEnrollmentStatusStudentsRequest;
use App\Http\Resources\Api\V1\Dashboard\EnrollmentStatusOverviewResource;
use App\Http\Resources\Api\V1\Dashboard\EnrollmentStatusSectionsResource;
use App\Http\Resources\Api\V1\Dashboard\EnrollmentStatusStudentDetailResource;
use App\Http\Resources\Api\V1\Dashboard\EnrollmentStatusStudentResource;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Enrollment Dashboard's drill-down: overview → sections of a department →
 * students → one student. The first two levels are aggregate-only and use the
 * same gate as `EnrollmentSummaryController`; the student levels use their own
 * gate because they are the audited ADR 0024 exception to ADR 0017.
 */
final class EnrollmentStatusController extends Controller
{
    public function overview(
        IndexDashboardRequest $request,
        BuildEnrollmentStatusOverview $buildOverview,
    ): JsonResponse {
        $this->authorize('view-enrollment-status');

        $overview = $buildOverview->execute(
            $this->resolveTerm($request->validated('academic_term_id')),
            $this->actor($request),
        );

        return $this->noStore(EnrollmentStatusOverviewResource::make($overview)->response($request));
    }

    public function sections(
        IndexEnrollmentStatusSectionsRequest $request,
        BuildEnrollmentStatusSections $buildSections,
    ): JsonResponse {
        $this->authorize('view-enrollment-status');

        $sections = $buildSections->execute(
            $this->resolveTerm($request->validated('academic_term_id')),
            $this->actor($request),
            CollegeCode::from((string) $request->validated('department')),
        );

        return $this->noStore(EnrollmentStatusSectionsResource::make($sections)->response($request));
    }

    public function students(
        IndexEnrollmentStatusStudentsRequest $request,
        ListEnrollmentStatusStudents $listStudents,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $this->authorize('view-enrollment-status-students');

        $group = $request->validated('group');

        $students = $listStudents->execute(
            $this->resolveTerm($request->validated('academic_term_id')),
            $this->actor($request),
            $contextFactory->fromRequest($request),
            CollegeCode::from((string) $request->validated('department')),
            $request->validated('section_code'),
            (bool) $request->validated('without_section', false),
            $group === null ? null : EnrollmentStatusGroup::from((string) $group),
            (int) $request->validated('page', 1),
            (int) $request->validated('per_page', 20),
        );

        return $this->noStore(EnrollmentStatusStudentResource::collection($students)->response($request));
    }

    public function student(
        IndexDashboardRequest $request,
        int $studentProfile,
        ShowEnrollmentStatusStudent $showStudent,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $this->authorize('view-enrollment-status-students');

        $detail = $showStudent->execute(
            $this->resolveTerm($request->validated('academic_term_id')),
            $this->actor($request),
            $contextFactory->fromRequest($request),
            $studentProfile,
        );

        return $this->noStore(EnrollmentStatusStudentDetailResource::make($detail)->response($request));
    }

    /**
     * The requested term, or the term currently in session — the same default
     * `EnrollmentSummaryController` uses.
     */
    private function resolveTerm(mixed $termId): AcademicTerm
    {
        $term = $termId !== null
            ? AcademicTerm::query()->where('id', $termId)->firstOrFail()
            : AcademicTerm::query()->where('status', AcademicTermStatus::SemesterOngoing)->first();

        if (! $term instanceof AcademicTerm) {
            throw new NotFoundHttpException('No academic_term_id was given and no term is currently active.');
        }

        return $term;
    }

    /**
     * @throws AuthenticationException
     */
    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
