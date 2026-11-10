<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organization\ArchiveAndCreateNextTerm;
use App\Actions\Organization\CreateAcademicTerm;
use App\Actions\Organization\TransitionAcademicTerm;
use App\Actions\Organization\UpdateDraftAcademicTermIdentity;
use App\Domain\Organization\AcademicTermStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcademicTerm\ArchiveAndCreateNextRequest;
use App\Http\Requests\Api\V1\AcademicTerm\StoreAcademicTermRequest;
use App\Http\Requests\Api\V1\AcademicTerm\UpdateAcademicTermRequest;
use App\Http\Requests\Api\V1\AcademicTerm\UpdateDraftAcademicTermIdentityRequest;
use App\Http\Resources\Api\V1\AcademicTermResource;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AcademicTermController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $this->authorize('viewAny', AcademicTerm::class);

        $currentSlotId = DB::table('academic_term_current_slots')
            ->where('id', 1)
            ->value('academic_term_id');

        $terms = AcademicTerm::query()
            ->visibleTo($user)
            ->when(
                $currentSlotId !== null,
                fn ($query) => $query->where(function ($q) use ($currentSlotId) {
                    $q->where('id', '<=', (int) $currentSlotId)
                        ->orWhere('status', AcademicTermStatus::Archived);
                }),
            )
            ->orderByDesc('school_year')
            ->orderBy('semester')
            ->get();

        return $this->cachePrivateResponse(AcademicTermResource::collection($terms)->response($request));
    }

    /**
     * @throws AuthenticationException
     */
    public function store(
        StoreAcademicTermRequest $request,
        CreateAcademicTerm $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('create', AcademicTerm::class);

        $term = $action->execute($user, [
            'school_year' => $request->validated('school_year'),
            'semester' => $request->validated('semester'),
            'enrollment_opens_at' => $request->validated('enrollment_opens_at'),
            'enrollment_closes_at' => $request->validated('enrollment_closes_at'),
            'add_drop_deadline_at' => $request->validated('add_drop_deadline_at'),
        ], $contextFactory->fromRequest($request));

        $response = AcademicTermResource::make($term)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateAcademicTermRequest $request,
        AcademicTerm $academicTerm,
        TransitionAcademicTerm $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $academicTerm);

        $term = $action->execute(
            $academicTerm,
            (string) $request->validated('action'),
            $user,
            $contextFactory->fromRequest($request),
        );

        return $this->cachePrivateResponse(AcademicTermResource::make($term)->response($request));
    }

    /**
     * Corrects a Draft term's school year and semester without changing the
     * term's enrollment schedule or lifecycle state.
     *
     * @throws AuthenticationException
     */
    public function updateDraftIdentity(
        UpdateDraftAcademicTermIdentityRequest $request,
        AcademicTerm $academicTerm,
        UpdateDraftAcademicTermIdentity $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $academicTerm);

        $term = $action->execute($academicTerm, [
            'school_year' => (string) $request->validated('school_year'),
            'semester' => (string) $request->validated('semester'),
        ], $user, $contextFactory->fromRequest($request));

        return $this->cachePrivateResponse(AcademicTermResource::make($term)->response($request));
    }

    /**
     * Archives the current term and opens the next one, returning the newly
     * created term — the Registrar's next screen is about the new cycle,
     * not the retired one.
     *
     * @throws AuthenticationException
     */
    public function archiveAndCreateNext(
        ArchiveAndCreateNextRequest $request,
        AcademicTerm $academicTerm,
        ArchiveAndCreateNextTerm $action,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $academicTerm);
        $this->authorize('create', AcademicTerm::class);

        [, $created] = $action->execute(
            $academicTerm,
            [
                'school_year' => (string) $request->validated('school_year'),
                'semester' => (string) $request->validated('semester'),
            ],
            $user,
            $contextFactory->fromRequest($request),
        );

        $response = AcademicTermResource::make($created)->response($request);
        $response->setStatusCode(201);

        return $this->cachePrivateResponse($response);
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
     * `private`: a student and a program chair receive different bodies
     * from this same URL, so no shared cache may retain either response.
     */
    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
