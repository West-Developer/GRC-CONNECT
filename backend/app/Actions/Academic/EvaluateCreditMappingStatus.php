<?php

namespace App\Actions\Academic;

use App\Domain\Academic\CreditMappingStatusResult;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Identity\StudentType;
use App\Models\CurriculumMigration;
use App\Models\NoCreditDeclaration;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;

/**
 * Whether a Transferee's or Returnee's credit mapping still holds enrolment back.
 *
 * Owner's rule, 2026-10-15 (amends ADR 0026 / 0043): only a credit that is still with the Program
 * Chair (`pending`) holds the student back. Once the Chair has passed the credits to the Registrar
 * Head (`endorsed`) the student may go on to enrol; the Head's approval no longer gates enrolment.
 * An endorsed credit counts for the student provisionally (`ResolveCreditedSubjectIds`) and the
 * Prospectus marks it as awaiting the Head, who still approves or rejects it afterwards.
 *
 * A student with nothing to credit says so ("no subject to credit", `NoCreditDeclaration`): while it
 * waits for the Program Chair the student is held back, and once the Chair confirms it the mapping is
 * complete with no credit rows at all. It only counts while the student has no sent credit (a Returnee whose
 * credits were all rejected counts as having none); a credit that is sent takes over.
 */
final readonly class EvaluateCreditMappingStatus
{
    private const SUBMIT_HINT = 'You have subjects saved but not yet sent. Submit your list to your Program Head to start credit mapping.';

    public function execute(StudentProfile $student): CreditMappingStatusResult
    {
        $studentType = $student->student_type instanceof StudentType
            ? $student->student_type
            : StudentType::tryFrom((string) $student->student_type);

        // An existing student already has a record at the school: nothing to map before enrolling.
        if ($studentType === null || $studentType === StudentType::Freshman || $studentType === StudentType::ExistingStudent) {
            return new CreditMappingStatusResult(
                isCompleted: true,
                requiresCreditMapping: false,
                reason: null,
            );
        }

        $credits = TransfereeCredit::query()
            ->where('student_id', $student->id)
            ->get();

        // A draft is the student's own unsent list (ADR 0026, amended): it is not part of the
        // workflow until it is submitted, so it is never counted as a credit in progress or done.
        $draftCredits = $credits->where('status', TransfereeCreditStatus::Draft)->count();
        $totalCredits = $credits->count() - $draftCredits;
        $pendingCredits = $credits->where('status', TransfereeCreditStatus::Pending)->count();
        $endorsedCredits = $credits->where('status', TransfereeCreditStatus::Endorsed)->count();
        $approvedCredits = $credits->where('status', TransfereeCreditStatus::Approved)->count();
        $rejectedCredits = $credits->where('status', TransfereeCreditStatus::Rejected)->count();

        $hasCurriculumMigration = CurriculumMigration::query()
            ->where('student_id', $student->id)
            ->exists();

        // Only read when it can matter: a student with no credit left that counts. A credit the Chair rejected
        // counts for nothing, so a Returnee whose every credit was rejected may still say they have none.
        $noCreditDeclaration = $totalCredits - $rejectedCredits === 0
            ? NoCreditDeclaration::query()->where('student_id', $student->id)->first()
            : null;

        if ($studentType === StudentType::Transferee) {
            if ($noCreditDeclaration !== null && $totalCredits === 0) {
                return $this->noCreditResult($noCreditDeclaration, $hasCurriculumMigration);
            }

            if ($totalCredits === 0) {
                return new CreditMappingStatusResult(
                    isCompleted: false,
                    requiresCreditMapping: true,
                    reason: $draftCredits > 0
                        ? self::SUBMIT_HINT
                        : 'Credit mapping has not been submitted or completed yet. Please wait for the Program Head / Registrar Head to evaluate your credits from your previous school.',
                    totalCredits: 0,
                    pendingCredits: 0,
                    endorsedCredits: 0,
                    approvedCredits: 0,
                    rejectedCredits: 0,
                    hasCurriculumMigration: $hasCurriculumMigration,
                );
            }

            if ($pendingCredits > 0) {
                return new CreditMappingStatusResult(
                    isCompleted: false,
                    requiresCreditMapping: true,
                    reason: 'Credit mapping is currently in progress. Please wait for the Program Head to map your credits and send them to the Registrar Head.',
                    totalCredits: $totalCredits,
                    pendingCredits: $pendingCredits,
                    endorsedCredits: $endorsedCredits,
                    approvedCredits: $approvedCredits,
                    rejectedCredits: $rejectedCredits,
                    hasCurriculumMigration: $hasCurriculumMigration,
                );
            }

            return new CreditMappingStatusResult(
                isCompleted: true,
                requiresCreditMapping: true,
                reason: null,
                totalCredits: $totalCredits,
                pendingCredits: $pendingCredits,
                endorsedCredits: $endorsedCredits,
                approvedCredits: $approvedCredits,
                rejectedCredits: $rejectedCredits,
                hasCurriculumMigration: $hasCurriculumMigration,
            );
        }

        // Returnee logic (remaining case)
        if ($noCreditDeclaration !== null && ! $hasCurriculumMigration) {
            return $this->noCreditResult($noCreditDeclaration, false);
        }

        if ($pendingCredits > 0) {
            return new CreditMappingStatusResult(
                isCompleted: false,
                requiresCreditMapping: true,
                reason: 'Credit mapping is currently in progress. Please wait for the Program Head to send your credits to the Registrar Head.',
                totalCredits: $totalCredits,
                pendingCredits: $pendingCredits,
                endorsedCredits: $endorsedCredits,
                approvedCredits: $approvedCredits,
                rejectedCredits: $rejectedCredits,
                hasCurriculumMigration: $hasCurriculumMigration,
            );
        }

        if ($hasCurriculumMigration || $approvedCredits > 0 || $endorsedCredits > 0) {
            return new CreditMappingStatusResult(
                isCompleted: true,
                requiresCreditMapping: true,
                reason: null,
                totalCredits: $totalCredits,
                pendingCredits: $pendingCredits,
                endorsedCredits: $endorsedCredits,
                approvedCredits: $approvedCredits,
                rejectedCredits: $rejectedCredits,
                hasCurriculumMigration: $hasCurriculumMigration,
            );
        }

        return new CreditMappingStatusResult(
            isCompleted: false,
            requiresCreditMapping: true,
            reason: $draftCredits > 0
                ? self::SUBMIT_HINT
                : 'Your credit mapping or curriculum migration has not been completed yet. Please contact your Program Head or Registrar Head before enrolling.',
            totalCredits: $totalCredits,
            pendingCredits: $pendingCredits,
            endorsedCredits: $endorsedCredits,
            approvedCredits: $approvedCredits,
            rejectedCredits: $rejectedCredits,
            hasCurriculumMigration: false,
        );
    }

    private function noCreditResult(NoCreditDeclaration $declaration, bool $hasCurriculumMigration): CreditMappingStatusResult
    {
        return new CreditMappingStatusResult(
            isCompleted: $declaration->isConfirmed(),
            requiresCreditMapping: true,
            reason: $declaration->isConfirmed()
                ? null
                : 'You told your Program Head you have no subject to credit. Please wait for your Program Head to review it.',
            hasCurriculumMigration: $hasCurriculumMigration,
        );
    }
}
