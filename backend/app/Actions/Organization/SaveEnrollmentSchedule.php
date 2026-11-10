<?php

namespace App\Actions\Organization;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\AcademicTerm;
use App\Models\AcademicTermEnrollmentWindow;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class SaveEnrollmentSchedule
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /**
     * @param  array{
     *     enrollment_opens_at: string,
     *     enrollment_closes_at: string,
     *     add_drop_opens_at?: ?string,
     *     add_drop_closes_at?: ?string,
     *     enrollment_platform?: ?string,
     *     windows: list<array{audience: string, opens_at: ?string, closes_at: ?string}>
     * }  $validatedData
     */
    public function execute(
        AcademicTerm $term,
        array $validatedData,
        User $actor,
        AuditRequestContext $context,
    ): AcademicTerm {
        return DB::transaction(function () use ($term, $validatedData, $actor, $context): AcademicTerm {
            $lockedTerm = AcademicTerm::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();
            $before = self::snapshot($lockedTerm);

            $termUpdate = [
                'enrollment_opens_at' => $validatedData['enrollment_opens_at'],
                'enrollment_closes_at' => $validatedData['enrollment_closes_at'],
            ];

            if (array_key_exists('add_drop_opens_at', $validatedData)) {
                $termUpdate['add_drop_opens_at'] = $validatedData['add_drop_opens_at'];
            }
            if (array_key_exists('add_drop_closes_at', $validatedData)) {
                $termUpdate['add_drop_deadline_at'] = $validatedData['add_drop_closes_at'];
            }

            if (array_key_exists('enrollment_platform', $validatedData)) {
                $termUpdate['enrollment_platform'] = $validatedData['enrollment_platform'];
            }

            $lockedTerm->update($termUpdate);
            $lockedTerm->refresh();

            foreach ($validatedData['windows'] as $window) {
                AcademicTermEnrollmentWindow::query()->updateOrCreate(
                    ['academic_term_id' => $lockedTerm->id, 'audience' => $window['audience']],
                    ['opens_at' => $window['opens_at'] ?? null, 'closes_at' => $window['closes_at'] ?? null],
                );
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::ACADEMIC_TERM_ENROLLMENT_SCHEDULE_UPDATED,
                AuditableType::ACADEMIC_TERM,
                $lockedTerm->id,
                $before,
                self::snapshot($lockedTerm),
                null,
                $context,
            );

            return $lockedTerm;
        });
    }

    /**
     * @return array{enrollment_opens_at: ?string, enrollment_closes_at: ?string, add_drop_opens_at: ?string, add_drop_deadline_at: ?string, enrollment_platform: ?string}
     */
    private static function snapshot(AcademicTerm $term): array
    {
        return [
            'enrollment_opens_at' => $term->enrollment_opens_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'enrollment_closes_at' => $term->enrollment_closes_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'add_drop_opens_at' => $term->add_drop_opens_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'add_drop_deadline_at' => $term->add_drop_deadline_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'enrollment_platform' => $term->enrollment_platform?->value,
        ];
    }
}
