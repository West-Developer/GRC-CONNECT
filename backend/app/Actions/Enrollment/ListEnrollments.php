<?php

namespace App\Actions\Enrollment;

use App\Domain\Organization\AcademicTermStatus;
use App\Models\Enrollment;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * PRD §5.3 FR-FIN-001 (Registrar approval queue with filters/pagination) and
 * FR-FIN-005 (Accounting sees only `pending_payment`). `Enrollment::scopeVisibleTo`
 * resolves "which rows" per role; this Action applies the optional filters on
 * top and paginates, the same shape as `App\Actions\Audit\ListAuditLogs`.
 */
final readonly class ListEnrollments
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Enrollment>
     */
    public function execute(User $actor, array $filters): LengthAwarePaginator
    {
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $academicTermId = isset($filters['academic_term_id']) ? (int) $filters['academic_term_id'] : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;
        $overMaximumOnly = isset($filters['exceeds_unit_maximum']) && filter_var($filters['exceeds_unit_maximum'], FILTER_VALIDATE_BOOLEAN);
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        $enrollments = Enrollment::query()
            ->visibleTo($actor)
            ->with(['student.user', 'student.curriculum', 'academicTerm.enrollmentWindows', 'enrollmentSubjects.section.subject', 'enrollmentSubjects.section.professor', 'queueTicket', 'assessment.items', 'revisions'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($academicTermId !== null, fn ($query) => $query->where('academic_term_id', $academicTermId))
            ->when(isset($filters['enrollment_ids']), fn ($query) => $query->whereKey($filters['enrollment_ids']))
            ->when(filter_var($filters['without_queue_ticket'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($query) => $query->whereDoesntHave('queueTicket'))
            ->when(filter_var($filters['current_term'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($query) => $query->whereHas('academicTerm', fn ($term) => $term->where('status', AcademicTermStatus::SemesterOngoing)))
            ->when($overMaximumOnly, fn ($query) => $query->whereNotNull('unit_maximum')->whereColumn('total_units', '>', 'unit_maximum'))
            ->when($search !== null && $search !== '', function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    if (is_numeric($search)) {
                        $sub->where('id', (int) $search);
                    }
                    $sub->orWhereHas('student', function ($sq) use ($search) {
                        $sq->where('student_number', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($uq) use ($search) {
                                $uq->where('name', 'like', "%{$search}%")
                                    ->orWhere('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            });
                    });
                });
            })
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        // Every row renders its queue position; compute the whole page's positions
        // from one query instead of one to three COUNT queries per waiting ticket
        // on every poll (ADR 0029).
        QueueTicket::preloadPositions(
            array_values(array_filter(array_map(
                static fn (Enrollment $enrollment): ?QueueTicket => $enrollment->queueTicket,
                $enrollments->items(),
            ))),
        );

        return $enrollments;
    }
}
