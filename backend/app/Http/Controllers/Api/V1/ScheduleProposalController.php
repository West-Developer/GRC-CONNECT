<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\CreateScheduleProposal;
use App\Actions\Scheduling\TransitionScheduleProposal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ScheduleProposal\StoreScheduleProposalRequest;
use App\Http\Requests\Api\V1\ScheduleProposal\UpdateScheduleProposalRequest;
use App\Http\Resources\Api\V1\ScheduleProposalResource;
use App\Http\Resources\Api\V1\ScheduleReviewSectionResource;
use App\Models\ScheduleProposal;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ScheduleProposalController extends Controller
{
    /**
     * Which Policy ability governs each transition action — see
     * ScheduleProposalPolicy and ADR 0011 for why one route serves multiple
     * roles instead of a single `role:` middleware.
     *
     * @var array<string, string>
     */
    private const ABILITY_FOR_ACTION = [
        'dean_approve' => 'approveAsDean',
        'dean_return' => 'approveAsDean',
        'executive_return' => 'approveAsExecutive',
        'publish' => 'publish',
        'close' => 'close',
    ];

    /**
     * @throws AuthenticationException
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $this->authorize('viewAny', ScheduleProposal::class);

        $proposals = ScheduleProposal::query()
            ->with(['academicTerm:id,school_year,semester', 'submitter:id,name'])
            ->visibleTo($user)
            ->when($user->role->value === 'program_chair', fn ($query) => $query->where('college', $user->college?->value))
            ->orderByDesc('academic_term_id')
            ->orderByDesc('created_at')
            ->get();

        return $this->cachePrivateResponse(ScheduleProposalResource::collection($proposals)->response($request));
    }

    /** @throws AuthenticationException */
    public function sections(Request $request, ScheduleProposal $scheduleProposal): JsonResponse
    {
        $this->authenticatedUser($request);
        $this->authorize('view', $scheduleProposal);

        $sections = Section::query()
            ->with(['subject:id,code,title,units', 'professor:id,name'])
            ->where('academic_term_id', $scheduleProposal->academic_term_id)
            ->when(
                $scheduleProposal->college !== null,
                fn ($query) => $query->whereHas(
                    'sectionPlan',
                    fn ($plans) => $plans->where('college', $scheduleProposal->college),
                ),
            )
            ->orderBy('section_code')
            ->orderBy('id')
            ->get();

        return $this->cachePrivateResponse(
            ScheduleReviewSectionResource::collection($sections)->response($request),
        );
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreScheduleProposalRequest $request,
        CreateScheduleProposal $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('create', ScheduleProposal::class);

        $proposal = $action->execute(
            (int) $request->validated('academic_term_id'),
            $user,
            $contextFactory->fromRequest($request),
        );

        $response = ScheduleProposalResource::make($proposal)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateScheduleProposalRequest $request,
        ScheduleProposal $scheduleProposal,
        TransitionScheduleProposal $transitioner,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);

        $action = $request->validated('action');
        $ability = self::ABILITY_FOR_ACTION[$action];

        $this->authorize($ability, ScheduleProposal::class);

        $proposal = $transitioner->execute(
            $scheduleProposal,
            $action,
            $user,
            $request->validated('decision_reason'),
            $contextFactory->fromRequest($request),
        );

        return $this->cachePrivateResponse(
            ScheduleProposalResource::make($proposal)->response($request),
        );
    }

    /**
     * @throws AuthenticationException
     */
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * `private`: results and authoring rights differ by role, so no shared
     * cache may retain any response from these endpoints.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
