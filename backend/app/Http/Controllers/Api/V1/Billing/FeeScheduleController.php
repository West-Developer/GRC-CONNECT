<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Actions\Billing\SnapshotFeeSchedule;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Billing\UpdateFeeScheduleRequest;
use App\Http\Resources\Api\V1\Billing\FeeScheduleResource;
use App\Models\FeeSchedule;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditRequestContextFactory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class FeeScheduleController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        abort_unless(
            $actor->role === UserRole::RegistrarHead || $actor->role === UserRole::AccountingStaff,
            403,
            'Fee schedules can only be viewed by the Registrar Head or Accounting Staff.',
        );

        $schedules = FeeSchedule::query()->orderBy('sort_order')->get();

        return FeeScheduleResource::collection($schedules);
    }

    /**
     * @throws AuthenticationException
     */
    public function update(
        UpdateFeeScheduleRequest $request,
        AuditRecorder $auditRecorder,
        AuditRequestContextFactory $contextFactory,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        abort_unless(
            $actor->role === UserRole::AccountingStaff,
            403,
            'Only Accounting Staff may configure fee schedules.',
        );

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $actor, $auditRecorder, $contextFactory, $request): void {
            $before = SnapshotFeeSchedule::current();

            // 1. Update or create tuition rate per unit
            FeeSchedule::updateOrCreate(
                ['category' => 'tuition'],
                [
                    'label' => 'Tuition Rate Per Unit',
                    'amount' => $validated['tuition_rate_per_unit'],
                    'program_codes' => null,
                    'is_active' => true,
                    'sort_order' => 1,
                ],
            );

            // 2. Synchronize miscellaneous fees
            $submittedIds = [];
            $order = 2;

            foreach ($validated['miscellaneous_fees'] as $fee) {
                $id = $fee['id'] ?? null;
                $feeSchedule = null;

                if ($id !== null) {
                    $feeSchedule = FeeSchedule::query()
                        ->where('id', $id)
                        ->where('category', 'miscellaneous')
                        ->first();
                }

                if ($feeSchedule !== null) {
                    $feeSchedule->update([
                        'label' => $fee['label'],
                        'amount' => $fee['amount'],
                        'semester' => $fee['semester'] ?? null,
                        'program_codes' => $fee['program_codes'] ?? null,
                        'is_active' => $fee['is_active'] ?? true,
                        'sort_order' => $fee['sort_order'] ?? $order++,
                    ]);
                    $submittedIds[] = $feeSchedule->id;
                } else {
                    $created = FeeSchedule::create([
                        'category' => 'miscellaneous',
                        'label' => $fee['label'],
                        'amount' => $fee['amount'],
                        'semester' => $fee['semester'] ?? null,
                        'program_codes' => $fee['program_codes'] ?? null,
                        'is_active' => $fee['is_active'] ?? true,
                        'sort_order' => $fee['sort_order'] ?? $order++,
                    ]);
                    $submittedIds[] = $created->id;
                }
            }

            // Remove any miscellaneous fee not present in the submitted list
            FeeSchedule::query()
                ->where('category', 'miscellaneous')
                ->whereNotIn('id', $submittedIds)
                ->delete();

            $after = SnapshotFeeSchedule::current();

            // A save that changes nothing leaves no audit record.
            if ($before !== $after) {
                $auditRecorder->record(
                    $actor,
                    AuditAction::FEE_SCHEDULE_UPDATED,
                    AuditableType::FEE_SCHEDULE,
                    null,
                    $before,
                    $after,
                    null,
                    $contextFactory->fromRequest($request),
                );
            }
        });

        $schedules = FeeSchedule::query()->orderBy('sort_order')->get();

        return response()->json([
            'message' => 'Fee schedules updated successfully.',
            'data' => FeeScheduleResource::collection($schedules),
        ]);
    }
}
