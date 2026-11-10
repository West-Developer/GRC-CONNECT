<?php

namespace App\Actions\Organization;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateDraftAcademicTermIdentity
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /**
     * @param  array{school_year: string, semester: string}  $identity
     */
    public function execute(
        AcademicTerm $term,
        array $identity,
        User $actor,
        AuditRequestContext $context,
    ): AcademicTerm {
        try {
            return DB::transaction(function () use ($term, $identity, $actor, $context): AcademicTerm {
                $lockedTerm = AcademicTerm::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();

                if ($lockedTerm->status !== AcademicTermStatus::Draft) {
                    throw ValidationException::withMessages([
                        'school_year' => 'Only Draft terms can have their school year and semester corrected.',
                    ]);
                }

                $duplicateExists = AcademicTerm::query()
                    ->where('school_year', $identity['school_year'])
                    ->where('semester', $identity['semester'])
                    ->whereKeyNot($lockedTerm->id)
                    ->lockForUpdate()
                    ->exists();

                if ($duplicateExists) {
                    throw ValidationException::withMessages([
                        'school_year' => 'A term for this school year and semester combination already exists.',
                    ]);
                }

                $before = self::snapshot($lockedTerm);
                $lockedTerm->update($identity);
                $lockedTerm->refresh();

                $this->auditRecorder->record(
                    $actor,
                    AuditAction::ACADEMIC_TERM_DRAFT_IDENTITY_UPDATED,
                    AuditableType::ACADEMIC_TERM,
                    $lockedTerm->id,
                    $before,
                    self::snapshot($lockedTerm),
                    null,
                    $context,
                );

                return $lockedTerm;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'school_year' => 'A term for this school year and semester combination already exists.',
            ]);
        }
    }

    /**
     * @return array{school_year: string, semester: string}
     */
    private static function snapshot(AcademicTerm $term): array
    {
        return [
            'school_year' => $term->school_year,
            'semester' => $term->semester,
        ];
    }
}
