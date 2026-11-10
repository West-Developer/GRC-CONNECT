<?php

namespace App\Actions\Scheduling;

use App\Domain\Identity\UserRole;
use App\Models\SectionChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Registrar Head sees every request; a Program Head sees the requests for
 * sections planned by their own college (or, with no college on the account,
 * only the ones they filed).
 */
final class ListSectionChangeRequests
{
    /**
     * @return Collection<int, SectionChangeRequest>
     */
    public function execute(User $viewer, ?string $status, ?int $sectionId): Collection
    {
        $query = SectionChangeRequest::query()
            ->with(['section.subject', 'requester', 'decider'])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id');

        if ($viewer->role === UserRole::ProgramChair) {
            $college = $viewer->college?->value;
            $query->where(function ($scope) use ($viewer, $college): void {
                $scope->where('requested_by', $viewer->id);
                if ($college !== null) {
                    $scope->orWhereHas('section.sectionPlan', fn ($plan) => $plan->where('college', $college));
                }
            });
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($sectionId !== null) {
            $query->where('section_id', $sectionId);
        }

        return $query->limit(200)->get();
    }
}
